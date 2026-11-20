<?php

namespace App\Integrations\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Integrations\Exceptions\PermanentIntegrationError;

/**
 * Sin pasarela (`PAYMENTS_MODE=null`): solo existe el pago manual (un admin de plataforma marca la factura
 * como pagada). Todo intento de cobro automático falla con un error permanente claro.
 */
class NullPaymentGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function createCustomer(string $reference, ?string $email, string $idempotencyKey): string
    {
        throw $this->disabled();
    }

    public function attachPaymentMethod(string $customerId, string $token): GatewayPaymentMethod
    {
        throw $this->disabled();
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        throw $this->disabled();
    }

    public function refund(RefundRequest $request): RefundResult
    {
        throw $this->disabled();
    }

    public function retrieve(string $gatewayPaymentId): ChargeResult
    {
        throw $this->disabled();
    }

    private function disabled(): PermanentIntegrationError
    {
        return new PermanentIntegrationError('No hay pasarela de pago configurada (PAYMENTS_MODE=null): solo pago manual', 'payments');
    }
}
