<?php

namespace App\Contracts;

use App\Enums\InvoiceReason;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\LineSpec;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Facturas (docs/billing.md §Facturas). Se emiten con número de `SequenceInterface` y quedan inmutables.
 */
interface InvoiceServiceInterface
{
    /**
     * Emite una factura `open` con su número y líneas. INTERNO: dentro de una transacción con la suscripción
     * bloqueada; el número se reserva en esa misma transacción (si se revierte, el número vuelve a estar libre).
     *
     * @param  list<LineSpec>  $lines
     * @param  array<string, mixed>  $metadata
     */
    public function issue(Subscription $subscription, InvoiceReason $reason, ?PlanPrice $price, CarbonInterface $periodStart, CarbonInterface $periodEnd, array $lines, array $metadata = []): Invoice;

    /**
     * Comprueba que el alcance de numeración configurado (`tenant`|`global`) es el de las facturas ya emitidas.
     *
     * @throws BillingException `invoice_numbering_scope_changed` (409)
     */
    public function assertNumberingScope(): void;

    /**
     * Facturas del tenant, paginadas en SQL. Exige `tenant-billing.read` del actor en ese tenant.
     *
     * @param  array<string, mixed>  $filters  `status`
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginate(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator;

    /** @throws BillingException `not_found` (404) si no es del tenant */
    public function find(Tenant $tenant, User $actor, int $invoiceId): Invoice;

    /**
     * PDF de una factura del tenant (`PdfRendererInterface`, vista `billing.invoice-pdf`). Mismo permiso y rango que `find()`.
     * Emisor = la marca de la PLATAFORMA (`BrandResolverInterface` sin el ajuste del tenant: el cliente no altera al emisor).
     *
     * @return array{filename: string, content: string}
     *
     * @throws BillingException `not_found` (404) si no es del tenant
     */
    public function pdf(Tenant $tenant, User $actor, int $invoiceId): array;

    /**
     * Pago manual (admin de plataforma): marca pagada una factura `open` o `uncollectible` con referencia y motivo,
     * auditado (`billing.manual-payment`, motivo obligatorio). Aplica los mismos efectos que un cobro por la pasarela.
     *
     * @throws BillingException `invoice_not_open` (409), `payment_in_progress` (409), `not_found` (404)
     */
    public function markPaidManually(int $invoiceId, User $actor, string $reference, string $reason): Invoice;
}
