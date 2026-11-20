<?php

namespace App\Services\Billing;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Exceptions\BillingException;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentDiscrepancyService implements PaymentDiscrepancyServiceInterface
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function record(string $kind, string $gateway, string $reference, ?Payment $payment, ?int $amountMinor, ?string $currency, array $details = []): bool
    {
        $tenantId = ($payment instanceof Payment ? $payment->tenant_id : $this->tenants->id()) ?? throw new \LogicException('Una discrepancia necesita el tenant en el contexto');

        $inserted = DB::table('payment_discrepancies')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'payment_id' => $payment?->id,
            'invoice_id' => $payment?->invoice_id,
            'kind' => $kind,
            'gateway' => $gateway,
            'reference' => mb_substr($reference, 0, 191),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'status' => PaymentDiscrepancy::STATUS_OPEN,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;

        if ($inserted) {
            // Alerta: el dinero real y el libro local no coinciden y hace falta una persona.
            LogService::error('billing: discrepancia con la pasarela', ['kind' => $kind, 'gateway' => $gateway, 'reference' => $reference, 'tenant_id' => $tenantId, 'payment_id' => $payment?->id], 'security');
        }

        return $inserted;
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return TenantQuery::withoutTenant(fn () => PaymentDiscrepancy::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['tenant_id']), fn ($q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->orderByDesc('detected_at')->orderByDesc('id')
            ->paginate($perPage), 'bandeja de discrepancias de pagos (plataforma)');
    }

    public function resolve(int $id, User $actor, string $note): PaymentDiscrepancy
    {
        $tenantId = TenantQuery::withoutTenant(fn () => DB::table('payment_discrepancies')->where('id', $id)->whereNull('deleted_at')->value('tenant_id'), 'resolver una discrepancia de pagos');

        if ($tenantId === null) {
            throw BillingException::invoiceNotFound();
        }

        return $this->tenants->run(Tenant::query()->findOrFail($tenantId), fn () => DB::transaction(function () use ($id, $actor, $note): PaymentDiscrepancy {
            $d = PaymentDiscrepancy::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($d->status === PaymentDiscrepancy::STATUS_OPEN) {
                $d->forceFill(['status' => PaymentDiscrepancy::STATUS_RESOLVED, 'resolved_at' => now(), 'resolved_by' => $actor->getKey(), 'resolution_note' => mb_substr($note, 0, 500)])->save();
                $this->audit->recordAction('billing.discrepancy-resolved', $d, $note, ['kind' => $d->kind, 'reference' => $d->reference], $d->tenant_id);
            }

            return $d;
        }));
    }
}
