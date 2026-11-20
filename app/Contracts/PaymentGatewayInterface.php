<?php

namespace App\Contracts;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Payments\ChargeRequest;
use App\Integrations\Payments\ChargeResult;
use App\Integrations\Payments\GatewayPaymentMethod;
use App\Integrations\Payments\RefundRequest;
use App\Integrations\Payments\RefundResult;

/**
 * Pasarela de pago (docs/billing.md §Pasarela, patrón de docs/integrations.md). Describe lo que necesita la app,
 * no el SDK del proveedor. Un cobro rechazado por el banco NO es una excepción: vuelve en `ChargeResult` con
 * `succeeded = false`. Las excepciones (`IntegrationException`) son fallos de infraestructura: `Transient`/
 * `RateLimited` se reintentan con LA MISMA clave de idempotencia; el resto no.
 *
 * Toda operación con efecto externo lleva su clave de idempotencia: repetirla devuelve el mismo resultado.
 */
interface PaymentGatewayInterface
{
    /** Nombre corto de la pasarela (`fake`, `null`, `stripe`...). Se guarda en `payments.gateway`. */
    public function name(): string;

    /**
     * @return string id del cliente en la pasarela
     *
     * @throws IntegrationException
     */
    public function createCustomer(string $reference, ?string $email, string $idempotencyKey): string;

    /**
     * Asocia al cliente un método de pago a partir del token que dio la pasarela al front (nunca datos de tarjeta).
     *
     * @throws IntegrationException
     */
    public function attachPaymentMethod(string $customerId, string $token): GatewayPaymentMethod;

    /**
     * @throws IntegrationException
     */
    public function charge(ChargeRequest $request): ChargeResult;

    /**
     * @throws IntegrationException
     */
    public function refund(RefundRequest $request): RefundResult;

    /**
     * Consulta un cobro por su id en la pasarela.
     *
     * @throws IntegrationException
     */
    public function retrieve(string $gatewayPaymentId): ChargeResult;
}
