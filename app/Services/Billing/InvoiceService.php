<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\BrandResolverInterface;
use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PdfRendererInterface;
use App\Contracts\SequenceInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\Billing\InvoiceIssued;
use App\Events\Billing\InvoicePaid;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use App\Support\Tenancy\TenantRoles;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Emisión y consulta de facturas. Importes con `Money` (enteros en unidad menor), numeración con `SequenceInterface`
 * reservada DENTRO de la transacción de la factura (sin huecos: si se revierte, el número vuelve a estar libre).
 * Orden de bloqueos en todo el módulo: suscripción -> factura -> pago (evita interbloqueos).
 */
class InvoiceService implements InvoiceServiceInterface
{
    public function __construct(
        private readonly SequenceInterface $sequences,
        private readonly AuditServiceInterface $audit,
        private readonly SubscriptionLifecycleInterface $lifecycle,
        private readonly TenantContext $tenants,
        private readonly PdfRendererInterface $pdf,
        private readonly BrandResolverInterface $brand,
    ) {}

    public function issue(Subscription $subscription, InvoiceReason $reason, ?PlanPrice $price, CarbonInterface $periodStart, CarbonInterface $periodEnd, array $lines, array $metadata = []): Invoice
    {
        $currency = $lines[0]->unitAmount->currency ?? $subscription->currency;

        $subtotal = Money::zero($currency);
        foreach ($lines as $line) {
            $subtotal = $subtotal->add($line->amount());
        }

        /** @var numeric-string $rate */
        $rate = (string) config('billing.tax.rate', '0');
        $tax = $subtotal->isPositive() && bccomp($rate, '0', 6) > 0 ? $subtotal->percentage($rate, Rounding::HalfUp) : Money::zero($currency);

        $invoice = new Invoice;
        $invoice->forceFill([
            'subscription_id' => $subscription->id,
            'plan_price_id' => $price?->id,
            'number' => $this->nextNumber($subscription->tenant_id),
            'number_scope' => $this->numberingScope(),
            'status' => InvoiceStatus::Open,
            'billing_reason' => $reason,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal->add($tax),
            'tax_rate' => $rate,
            'tax_label' => (string) config('billing.tax.label', ''),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issued_at' => now(),
            'due_at' => now()->addDays((int) config('billing.invoice.due_days', 0)),
            'metadata' => $metadata === [] ? null : $metadata,
        ])->save();

        foreach ($lines as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unitAmount,
                'amount' => $line->amount(),
                'period_start' => $line->periodStart,
                'period_end' => $line->periodEnd,
            ]);
        }

        $this->audit->recordAction('billing.invoice-issued', $invoice, null, [
            'number' => $invoice->number, 'total_minor' => $invoice->total->amountMinor, 'currency' => $invoice->currency, 'reason' => $reason->value,
        ], $subscription->tenant_id);

        InvoiceIssued::dispatch($invoice->id, $subscription->tenant_id, $subscription->id, (string) $invoice->number);

        return $invoice->load('lines');
    }

    public function paginate(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.read');

        return Invoice::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(Tenant $tenant, User $actor, int $invoiceId): Invoice
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.read');

        return Invoice::query()->with(['lines', 'payments'])->find($invoiceId) ?? throw BillingException::invoiceNotFound();
    }

    public function pdf(Tenant $tenant, User $actor, int $invoiceId): array
    {
        $invoice = $this->find($tenant, $actor, $invoiceId);

        // Quien emite es la plataforma: su marca, no la que el tenant haya configurado para sus correos.
        $issuer = TenantQuery::withoutTenant(fn () => $this->brand->get(), 'marca de la plataforma para el PDF de una factura', audit: false);
        $locale = str_replace('-', '_', (string) app()->getLocale());
        $money = fn (Money $m): string => $m->format($locale);

        $content = $this->pdf->renderView('billing.invoice-pdf', [
            'invoice' => $invoice,
            'issuer' => $issuer,
            'customer' => $tenant,
            'money' => $money,
        ]);

        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice->number ?? $invoice->id));

        return ['filename' => "factura-{$number}.pdf", 'content' => $content];
    }

    public function markPaidManually(int $invoiceId, User $actor, string $reference, string $reason): Invoice
    {
        $reason = $this->audit->requireReason('billing.manual-payment', $reason);

        $row = TenantQuery::withoutTenant(
            fn () => DB::table('invoices')->where('id', $invoiceId)->whereNull('deleted_at')->first(['id', 'tenant_id', 'subscription_id']),
            'pago manual de una factura (plataforma)',
        );

        if ($row === null) {
            throw BillingException::invoiceNotFound();
        }

        $tenant = Tenant::query()->findOrFail((int) $row->tenant_id);

        return $this->tenants->run($tenant, fn () => DB::transaction(function () use ($row, $actor, $reference, $reason): Invoice {
            $subscription = Subscription::query()->whereKey($row->subscription_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::Uncollectible], true)) {
                throw BillingException::invoiceNotOpen();
            }

            $pending = Payment::query()->where('invoice_id', $invoice->id)->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value])->lockForUpdate()->first();

            if ($pending instanceof Payment) {
                // La pasarela está cobrando ahora, o un intento quedó a medias (corte, timeout) y PUDO cobrar: no se sabe hasta
                // que `billing:renew` lo retome con la misma clave. Darlo por fallido y cobrar a mano arriesgaría un doble cobro.
                throw $pending->status === PaymentStatus::RequiresAction || $pending->claimed_until?->isFuture() ? BillingException::paymentInProgress() : BillingException::paymentPending();
            }

            $attempt = (int) Payment::query()->where('invoice_id', $invoice->id)->max('attempt') + 1;

            $payment = new Payment;
            $payment->forceFill([
                'invoice_id' => $invoice->id,
                'attempt' => $attempt,
                'method' => 'manual',
                'status' => PaymentStatus::Succeeded,
                'amount' => $invoice->total,
                'idempotency_key' => "inv-{$invoice->id}-manual-{$attempt}",
                'reference' => $reference,
                'reason' => $reason,
                'recorded_by' => $actor->getKey(),
                'processed_at' => now(),
            ])->save();

            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_at = now();
            $invoice->save();

            $this->lifecycle->onInvoicePaid($subscription, $invoice);

            $this->audit->recordAction('billing.manual-payment', $invoice, $reason, [
                'reference' => $reference, 'payment_id' => $payment->id, 'total_minor' => $invoice->total->amountMinor, 'currency' => $invoice->currency,
            ], $invoice->tenant_id);

            InvoicePaid::dispatch($invoice->id, $invoice->tenant_id, $invoice->subscription_id, 'manual');

            return $invoice->load(['lines', 'payments']);
        }));
    }

    public function assertNumberingScope(): void
    {
        $current = $this->numberingScope();
        $other = $current === 'global' ? 'tenant' : 'global';

        $exists = TenantQuery::withoutTenant(
            fn () => DB::table('invoices')->where('number_scope', $other)->exists(),
            'comprobación del alcance de numeración de facturas',
            audit: false,
        );

        if ($exists) {
            throw BillingException::numberingScopeChanged($current, $other);
        }
    }

    private function numberingScope(): string
    {
        return config('billing.invoice.sequence_scope') === 'tenant' ? 'tenant' : 'global';
    }

    private function nextNumber(int $tenantId): string
    {
        $this->assertNumberingScope();

        $name = (string) config('billing.invoice.sequence', 'billing_invoice');

        if ($this->numberingScope() === 'global') {
            // Una numeración para toda la plataforma: la reserva se hace "sin tenant" (secuencia global).
            return (string) TenantQuery::withoutTenant(fn () => $this->sequences->next($name), 'numeración global de facturas', audit: false);
        }

        return (string) $this->sequences->next($name, $tenantId);
    }
}
