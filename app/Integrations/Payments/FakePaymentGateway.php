<?php

namespace App\Integrations\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Integrations\Concerns\RecordsCalls;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\TransientIntegrationError;

/**
 * Pasarela falsa y determinista: sin red, reloj ni azar. Ids `fake_cus_1`, `fake_pay_1`... por instancia
 * (binding `scoped`, sin estado estático). La misma clave de idempotencia devuelve el MISMO resultado sin repetir el cobro.
 *
 * Comportamiento por token (el de `attachPaymentMethod`): `tok_visa` y cualquier otro `tok_*` cobran bien;
 * `tok_declined`, `tok_insufficient_funds` y `tok_expired` son rechazados por el banco. Para forzar un rechazo
 * puntual: `declineNext('card_declined', times: 2)`. Para un fallo de infraestructura: `failNext(TransientIntegrationError::class)`.
 * `tok_3ds` pide 3DS: con el cliente presente el cobro queda `requiresAction` (`completeAction($id)` simula que lo confirma);
 * sin cliente (renovación) se rechaza con `authentication_required`. `loseResponseNext()` simula el peor caso: la pasarela
 * COBRA y la respuesta se pierde (timeout). `findCharge`/`retrieve`/`cancelCharge`/`recentCharges` no consumen `failNext`
 * (usa `failNextLookup()` para simular que la consulta también falla).
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

    private int $lostResponses = 0;

    /** @var list<class-string<IntegrationException>> */
    private array $lookupFailures = [];

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

    /** Las próximas `$times` llamadas a `charge()` cobran de verdad y luego lanzan un error transitorio (respuesta perdida). */
    public function loseResponseNext(int $times = 1): static
    {
        $this->lostResponses += $times;

        return $this;
    }

    /**
     * @param  class-string<IntegrationException>  $type
     */
    public function failNextLookup(string $type = TransientIntegrationError::class, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->lookupFailures[] = $type;
        }

        return $this;
    }

    /** El cliente confirmó el 3DS: el cobro pasa a `succeeded` (lo que luego llegaría por webhook). */
    public function completeAction(string $paymentId): ChargeResult
    {
        $current = $this->chargesById[$paymentId] ?? throw new PermanentIntegrationError('Cobro no encontrado', 'payments');
        $done = $this->with($current, succeeded: true, requiresAction: false, failureCode: null, failureMessage: null, clientSecret: null);

        return $this->store($done);
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

        $extras = [
            'customerId' => $request->customerId,
            'amountMinor' => $request->amount->amountMinor,
            'currency' => $request->amount->currency,
            'idempotencyKey' => $request->idempotencyKey,
            'metadata' => $request->metadata,
        ];

        if ($code === null && $token === 'tok_3ds') {
            $result = $request->customerPresent
                ? new ChargeResult(false, $id, ...$extras, requiresAction: true, clientSecret: "{$id}_secret_fake")
                : new ChargeResult(false, $id, 'authentication_required', 'La tarjeta pide autenticación del cliente', ...$extras);
        } else {
            $result = $code === null
                ? new ChargeResult(true, $id, ...$extras)
                : new ChargeResult(false, $id, $code, 'Cobro rechazado por la pasarela simulada', ...$extras);
        }

        $this->store($result);
        $this->chargesByKey[$request->idempotencyKey] = $result;

        if ($this->lostResponses > 0) {
            $this->lostResponses--;

            throw new TransientIntegrationError('Respuesta perdida (simulada)', 'payments');
        }

        return $result;
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

    public function findCharge(?string $customerId, string $idempotencyKey, int $sinceTimestamp): ?ChargeResult
    {
        $this->recordedCalls[] = ['operation' => 'findCharge', 'payload' => $idempotencyKey];
        $this->failLookupIfNeeded();

        return $this->chargesByKey[$idempotencyKey] ?? null;
    }

    public function cancelCharge(string $gatewayPaymentId): ChargeResult
    {
        $this->recordedCalls[] = ['operation' => 'cancelCharge', 'payload' => $gatewayPaymentId];
        $this->failLookupIfNeeded();

        $current = $this->chargesById[$gatewayPaymentId] ?? throw new PermanentIntegrationError('Cobro no encontrado', 'payments');

        if (! $current->requiresAction) {
            return $current;   // ya se cobró o ya falló: se informa de su estado real
        }

        return $this->store($this->with($current, requiresAction: false, failureCode: 'canceled', failureMessage: 'Cobro cancelado', clientSecret: null));
    }

    public function recentCharges(int $sinceTimestamp, ?string $cursor = null, int $limit = 100): array
    {
        $this->recordedCalls[] = ['operation' => 'recentCharges', 'payload' => $sinceTimestamp];
        $this->failLookupIfNeeded();

        return ['items' => array_slice(array_reverse(array_values($this->chargesById)), 0, $limit), 'next' => null];
    }

    /**
     * @return list<ChargeRequest>
     */
    public function charges(): array
    {
        return array_map(fn (array $call) => $call['payload'], $this->calls('charge'));
    }

    private function failLookupIfNeeded(): void
    {
        $failure = array_shift($this->lookupFailures);

        if ($failure !== null) {
            throw new $failure('Fallo inyectado en la consulta', 'payments');
        }
    }

    private function store(ChargeResult $result): ChargeResult
    {
        $this->chargesById[$result->paymentId] = $result;

        if ($result->idempotencyKey !== null) {
            $this->chargesByKey[$result->idempotencyKey] = $result;
        }

        return $result;
    }

    private function with(ChargeResult $c, bool $succeeded = false, bool $requiresAction = false, ?string $failureCode = null, ?string $failureMessage = null, ?string $clientSecret = null): ChargeResult
    {
        return new ChargeResult($succeeded, $c->paymentId, $failureCode, $failureMessage, $requiresAction, $c->processing, $clientSecret, $c->customerId, $c->amountMinor, $c->currency, $c->idempotencyKey, $c->metadata);
    }

    protected function serviceName(): string
    {
        return 'payments';
    }
}
