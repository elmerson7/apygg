<?php

namespace App\Integrations\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Integrations\Concerns\RecordsCalls;
use App\Integrations\Exceptions\PermanentIntegrationError;

/**
 * Pasarela falsa y determinista: sin red, reloj ni azar. Ids `fake_cus_1`, `fake_pay_1`... por instancia
 * (binding `scoped`, sin estado estático). La misma clave de idempotencia devuelve el MISMO resultado sin repetir el cobro.
 *
 * Comportamiento por token (el de `attachPaymentMethod`): `tok_visa` y cualquier otro `tok_*` cobran bien;
 * `tok_declined`, `tok_insufficient_funds` y `tok_expired` son rechazados por el banco. Para forzar un rechazo
 * puntual: `declineNext('card_declined', times: 2)`. Para un fallo de infraestructura: `failNext(TransientIntegrationError::class)`.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    use RecordsCalls;

    private const DECLINED_TOKENS = [
        'tok_declined' => 'card_declined',
        'tok_insufficient_funds' => 'insufficient_funds',
        'tok_expired' => 'expired_card',
    ];

    private int $counter = 0;

    /** @var array<string, ChargeResult> */
    private array $chargesByKey = [];

    /** @var array<string, ChargeResult> */
    private array $chargesById = [];

    /** @var array<string, RefundResult> */
    private array $refundsByKey = [];

    /** @var array<string, string> */
    private array $customersByKey = [];

    /** @var list<string> códigos de rechazo pendientes */
    private array $pendingDeclines = [];

    /** @var (\Closure(ChargeRequest): void)|null */
    private ?\Closure $chargeHook = null;

    /** Semilla de pruebas: se ejecuta dentro de `charge()` (p. ej. para simular una cancelación con el cobro en vuelo). */
    public function onCharge(?\Closure $hook): static
    {
        $this->chargeHook = $hook;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function declineNext(string $code = 'card_declined', int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->pendingDeclines[] = $code;
        }

        return $this;
    }

    public function createCustomer(string $reference, ?string $email, string $idempotencyKey): string
    {
        $this->record('createCustomer', ['reference' => $reference, 'idempotency_key' => $idempotencyKey]);

        return $this->customersByKey[$idempotencyKey] ??= 'fake_cus_'.++$this->counter;
    }

    public function attachPaymentMethod(string $customerId, string $token): GatewayPaymentMethod
    {
        $this->record('attachPaymentMethod', ['customer_id' => $customerId]);

        if (! str_starts_with($token, 'tok_')) {
            throw new PermanentIntegrationError('Token de método de pago no válido', 'payments');
        }

        return new GatewayPaymentMethod('fake_pm_'.$token, 'visa', '4242');
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $this->record('charge', $request);

        if (isset($this->chargesByKey[$request->idempotencyKey])) {
            return $this->chargesByKey[$request->idempotencyKey];
        }

        $token = (string) substr($request->paymentMethodId, strlen('fake_pm_'));
        $code = array_shift($this->pendingDeclines) ?? (self::DECLINED_TOKENS[$token] ?? null);
        $id = 'fake_pay_'.++$this->counter;

        if ($this->chargeHook !== null) {
            ($this->chargeHook)($request);
        }

        $result = $code === null
            ? new ChargeResult(true, $id)
            : new ChargeResult(false, $id, $code, 'Cobro rechazado por la pasarela simulada');

        $this->chargesById[$id] = $result;

        return $this->chargesByKey[$request->idempotencyKey] = $result;
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->record('refund', $request);

        return $this->refundsByKey[$request->idempotencyKey] ??= new RefundResult('fake_re_'.++$this->counter, true);
    }

    public function retrieve(string $gatewayPaymentId): ChargeResult
    {
        $this->record('retrieve', $gatewayPaymentId);

        return $this->chargesById[$gatewayPaymentId] ?? throw new PermanentIntegrationError('Cobro no encontrado', 'payments');
    }

    /**
     * @return list<ChargeRequest>
     */
    public function charges(): array
    {
        return array_map(fn (array $call) => $call['payload'], $this->calls('charge'));
    }

    protected function serviceName(): string
    {
        return 'payments';
    }
}
