<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionStatus;

/**
 * Errores de dominio de facturación y suscripciones. El `code` es estable (docs/billing.md): los clientes
 * ramifican por él, no por el mensaje.
 */
class BillingException extends ApiException
{
    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(string $message, int $status, private readonly string $stableCode, array $extensions = [])
    {
        parent::__construct($message, $status, [], $stableCode, null, $extensions);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function planNotFound(): self
    {
        return new self('El plan o el precio solicitado no existe o no está disponible.', 404, 'plan_not_found');
    }

    /** Plan privado (`public: false`): solo la plataforma lo asigna (`POST /admin/billing/tenants/{id}/plan`). */
    public static function planPrivate(): self
    {
        return new self('Ese plan es un acuerdo privado: solo el equipo de la plataforma puede asignarlo.', 403, 'plan_private');
    }

    public static function subscriptionExists(): self
    {
        return new self('La empresa ya tiene una suscripción vigente.', 409, 'subscription_exists');
    }

    public static function noSubscription(): self
    {
        return new self('La empresa no tiene una suscripción vigente.', 404, 'subscription_not_found');
    }

    public static function notActive(): self
    {
        return new self('La operación solo se puede hacer con la suscripción activa o en prueba.', 409, 'subscription_not_active');
    }

    public static function notResumable(): self
    {
        return new self('La suscripción no tiene una baja pendiente que reanudar.', 409, 'subscription_not_resumable');
    }

    public static function invalidTransition(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self("Transición de suscripción no permitida: {$from->value} -> {$to->value}.", 409, 'invalid_subscription_transition', ['from' => $from->value, 'to' => $to->value]);
    }

    public static function paymentMethodRequired(): self
    {
        return new self('Hace falta un método de pago para este plan.', 422, 'payment_method_required');
    }

    public static function paymentFailed(string $failureCode, ?string $message = null): self
    {
        return new self($message ?? 'El cobro fue rechazado.', 402, 'payment_failed', ['failure_code' => $failureCode]);
    }

    /** 3DS/SCA: el front debe confirmar el cobro con `client_secret` (p. ej. `stripe.confirmCardPayment`); el resultado llega por webhook. */
    public static function paymentRequiresAction(?string $clientSecret, ?string $paymentIntentId): self
    {
        return new self('El banco pide autenticar el pago: confírmalo desde la aplicación.', 402, 'payment_requires_action', ['client_secret' => $clientSecret, 'payment_intent_id' => $paymentIntentId]);
    }

    public static function paymentPending(): self
    {
        return new self('La pasarela no respondió: el cobro se reintentará automáticamente. Consulta la factura más tarde.', 409, 'payment_pending');
    }

    public static function paymentInProgress(): self
    {
        return new self('Ya hay un cobro en curso para esta factura.', 409, 'payment_in_progress');
    }

    public static function changeInProgress(): self
    {
        return new self('Hay un cambio de plan pendiente de cobro.', 409, 'change_in_progress');
    }

    public static function samePlan(): self
    {
        return new self('Ya estás en ese plan y precio.', 422, 'same_plan');
    }

    public static function discrepancyNotFound(): self
    {
        return new self('Discrepancia no encontrada.', 404, 'discrepancy_not_found');
    }

    public static function invoiceNotFound(): self
    {
        return new self('Factura no encontrada.', 404, 'not_found');
    }

    public static function invoiceNotOpen(): self
    {
        return new self('La factura no está pendiente de pago.', 409, 'invoice_not_open');
    }

    /**
     * La factura se anuló mientras la pasarela cobraba: el cargo no surte efecto y se devuelve (`refund`: `refunded`, o
     * `pending` si la pasarela falló y lo reintenta `billing:renew`). Mismo `code` que una factura no abierta.
     */
    public static function invoiceVoidedWhileCharging(bool $refunded): self
    {
        return new self(
            'La factura se anuló mientras se cobraba (la suscripción cambió a la vez): el cargo no se aplica y se '.($refunded ? 'ha devuelto' : 'devolverá automáticamente').'.',
            409,
            'invoice_not_open',
            ['refund' => $refunded ? 'refunded' : 'pending'],
        );
    }

    public static function invoiceImmutable(): self
    {
        return new self('Una factura emitida no se puede modificar.', 409, 'invoice_immutable');
    }

    public static function currencyUnavailable(): self
    {
        return new self('Ese plan no tiene precio en la moneda o intervalo pedidos.', 422, 'price_unavailable');
    }

    public static function numberingScopeChanged(string $current, string $issued): self
    {
        return new self("BILLING_INVOICE_SEQUENCE_SCOPE={$current}, pero ya hay facturas emitidas con alcance {$issued}: cambiarlo repetiría números. Restaura el valor anterior (o migra la numeración de forma explícita).", 409, 'invoice_numbering_scope_changed');
    }

    public static function invalidDays(): self
    {
        return new self('Los días concedidos deben estar entre 1 y el máximo permitido.', 422, 'invalid_free_period');
    }
}
