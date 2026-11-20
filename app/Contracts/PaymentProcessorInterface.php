<?php

namespace App\Contracts;

use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\PaymentOutcome;

/**
 * Cobro de una factura por la pasarela (docs/billing.md §Cobro). Tres fases para que nunca haya doble cobro:
 * 1) reservar el intento (fila `payments` con su clave de idempotencia, en transacción y con la suscripción bloqueada),
 * 2) llamar a la pasarela SIN transacción abierta, 3) registrar el resultado (bloqueando de nuevo, en el mismo orden).
 */
interface PaymentProcessorInterface
{
    /**
     * Debe llamarse con el tenant de la factura en el contexto.
     */
    public function collect(int $invoiceId): PaymentOutcome;

    /**
     * Reintento pedido por el cliente (p. ej. tras cambiar de tarjeta). Exige `tenant-billing.manage` del actor.
     * No consume reintentos del calendario de morosidad.
     *
     * @throws BillingException `payment_failed` (402), `payment_in_progress`/`invoice_not_open` (409)
     */
    public function pay(Tenant $tenant, User $actor, int $invoiceId): Invoice;

    /**
     * Cobros exitosos de la pasarela cuya factura quedó anulada y que aún no se han devuelto (de todos los tenants).
     *
     * @return list<array{id: int, tenant_id: int}>
     */
    public function orphanedPayments(int $limit): array;

    /**
     * Devuelve por la pasarela un cobro `succeeded` cuya factura está `void` (cancelación inmediata o ajuste anulado mientras
     * se cobraba). Clave de idempotencia `refund-<pago>`; con el tenant del pago en el contexto. Registra `refunded_at` y audita.
     * No lanza por fallos de la pasarela: devuelve false y se reintenta en el siguiente `billing:renew`.
     *
     * @return bool true si el reembolso se registró en esta llamada
     */
    public function refund(int $paymentId): bool;

    /**
     * Convierte el resultado de un cobro síncrono (alta, cambio de plan) en el error HTTP que corresponde.
     *
     * @throws BillingException `payment_failed` (402), `payment_pending`/`payment_in_progress`/`invoice_not_open` (409)
     */
    public function throwUnlessPaid(PaymentOutcome $outcome, int $invoiceId): void;
}
