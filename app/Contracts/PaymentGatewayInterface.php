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

    /**
     * Busca un cobro POR LA CLAVE DE IDEMPOTENCIA con la que se envió (la pasarela no la puede reutilizar para siempre:
     * Stripe las olvida a las 24 h). Es lo que se consulta ANTES de reenviar un cobro cuyo resultado se desconoce, para no
     * cobrar dos veces. `null` = la pasarela confirma que NO hay ningún cobro con esa clave (nunca llegó).
     *
     * @param  int  $sinceTimestamp  no se buscan cobros anteriores (segundos Unix)
     *
     * @throws IntegrationException si no se puede confirmar (el cobro sigue siendo incierto: no se reenvía)
     */
    public function findCharge(?string $customerId, string $idempotencyKey, int $sinceTimestamp): ?ChargeResult;

    /**
     * Cancela un cobro que espera acción del cliente. Si ya no se puede cancelar (se cobró), devuelve su estado real.
     *
     * @throws IntegrationException
     */
    public function cancelCharge(string $gatewayPaymentId): ChargeResult;

    /**
     * Cobros recientes de la cuenta (conciliación): del más nuevo al más viejo, hasta `$limit` por página.
     *
     * @param  string|null  $cursor  el `next` de la página anterior
     * @return array{items: list<ChargeResult>, next: string|null}
     *
     * @throws IntegrationException
     */
    public function recentCharges(int $sinceTimestamp, ?string $cursor = null, int $limit = 100): array;
}
