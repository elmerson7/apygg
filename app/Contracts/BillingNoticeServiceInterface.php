<?php

namespace App\Contracts;

/**
 * Avisos de facturación a los dueños y administradores del tenant (docs/billing.md §Avisos).
 *
 * Cada aviso sale UNA vez por (suscripción, tipo, clave de periodo o intento), aunque lo disparen a la vez un evento y el
 * comando, o dos procesos. Nunca lanza hacia el flujo de cobro: un fallo al avisar se registra y no deshace nada.
 */
interface BillingNoticeServiceInterface
{
    /** Pago de una factura (cobro de la pasarela o pago manual). */
    public function invoicePaid(int $tenantId, int $invoiceId): bool;

    /** Cobro de renovación rechazado; el último reintento antes de cancelar sale como «último aviso». */
    public function paymentFailed(int $tenantId, int $invoiceId, int $attempt, ?string $nextRetryAt): bool;

    /** La suscripción terminó (`canceled` o `expired`). */
    public function subscriptionEnded(int $tenantId, int $subscriptionId, string $status, string $reason): bool;

    /**
     * Barrido programado: fin de prueba y renovación próximas. Devuelve cuántos avisos nuevos salieron por tipo.
     *
     * @return array<string, int>
     */
    public function sendUpcoming(): array;
}
