<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Bandeja de discrepancias entre la pasarela y lo local (docs/billing.md §Conciliación).
 */
interface PaymentDiscrepancyServiceInterface
{
    /**
     * Registra un caso (idempotente por gateway + referencia + tipo) y, si es nuevo, deja una alerta en el log de seguridad.
     * Con el tenant del caso en el contexto. No debe llevar secretos ni datos de tarjeta en `$details`.
     *
     * @param  array<string, mixed>  $details
     * @return bool true si es un caso nuevo
     */
    public function record(string $kind, string $gateway, string $reference, ?Payment $payment, ?int $amountMinor, ?string $currency, array $details = []): bool;

    /**
     * Listado de plataforma (todos los tenants, auditado), paginado en SQL.
     *
     * @param  array<string, mixed>  $filters  `status`, `tenant_id`
     * @return LengthAwarePaginator<int, PaymentDiscrepancy>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Marca un caso como revisado (nota obligatoria, auditado).
     */
    public function resolve(int $id, User $actor, string $note): PaymentDiscrepancy;
}
