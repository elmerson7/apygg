<?php

namespace App\Integrations\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\QuotaExceededError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\IntegrationHttp;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Stripe por HTTP (sin SDK: solo usamos seis llamadas y el cliente de `IntegrationHttp` ya da timeouts, errores
 * tipados y `Http::fake` en los tests). docs/billing.md §Stripe.
 *
 * - Método de pago: el front crea un `PaymentMethod` (`pm_...`) con Stripe.js y manda su id como «token»; aquí se adjunta al
 *   cliente. Nunca pasan datos de tarjeta por la API.
 * - Cobro: `PaymentIntent` confirmado al crear, con `Idempotency-Key` = la clave del intento. `customerPresent = false`
 *   -> `off_session` (renovaciones: si pide 3DS, se rechaza con `authentication_required`); `true` -> si pide 3DS queda
 *   `requiresAction` con el `client_secret` para el front.
 * - Un rechazo del banco (HTTP 402 `card_error`) NO es una excepción: vuelve como `ChargeResult(false, ...)`.
 * - Errores de infraestructura: 429 -> RateLimited, 5xx/408/409(clave en uso)/red -> Transient, resto 4xx -> Permanent.
 * - Las claves de idempotencia de Stripe caducan a las 24 h: ANTES de reenviar un cobro incierto se consulta por clave
 *   (`findCharge`, que busca la clave en la metadata del PaymentIntent), nunca se reenvía a ciegas.
 * - La clave secreta nunca se registra: va solo en la cabecera `Authorization` y los errores no incluyen el cuerpo.
 */
class StripePaymentGateway implements PaymentGatewayInterface
{
    private const SERVICE = 'payments';

    /** Páginas máximas al buscar por clave (100 cobros por página): acota el coste de una consulta. */
    private const LOOKUP_PAGES = 5;

    /**
     * Monedas en las que Stripe NO usa la unidad menor ISO 4217 (docs.stripe.com/currencies, «Special cases»): ISK y UGX
     * son de cero decimales en ISO (y en `config/currencies.php`), pero Stripe exige enviarlas con dos decimales `00`
     * (5 ISK = `500`). Sin este factor se cobraría 100 veces menos.
     */
    private const STRIPE_FACTOR = ['ISK' => 100, 'UGX' => 100];

    /** Monedas de tres decimales: Stripe exige que el último dígito sea 0 (importe múltiplo de 10). */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * @param  array<string, mixed>  $config  `services.stripe`
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCustomer(string $reference, ?string $email, string $idempotencyKey): string
    {
        $body = ['name' => $reference, 'metadata' => ['reference' => $reference]];

        if ($email !== null) {
            $body['email'] = $email;
        }

        $customer = $this->post('/customers', $body, $idempotencyKey);

        return $this->string($customer, 'id');
    }

    public function attachPaymentMethod(string $customerId, string $token): GatewayPaymentMethod
    {
        if (! preg_match('/^pm_[A-Za-z0-9_]+$/', $token)) {
            throw new PermanentIntegrationError('El token de método de pago debe ser un PaymentMethod de Stripe (pm_...)', self::SERVICE);
        }

        $method = $this->post("/payment_methods/{$token}/attach", ['customer' => $customerId]);

        $card = is_array($method['card'] ?? null) ? $method['card'] : [];

        return new GatewayPaymentMethod($this->string($method, 'id'), isset($card['brand']) ? (string) $card['brand'] : null, isset($card['last4']) ? (string) $card['last4'] : null);
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $metadata = $request->metadata + ['idempotency_key' => $request->idempotencyKey];

        $body = [
            'amount' => self::toStripeAmount($request->amount),
            'currency' => strtolower($request->amount->currency),
            'customer' => $request->customerId,
            'payment_method' => $request->paymentMethodId,
            'confirm' => 'true',
            'payment_method_types' => ['card'],
            'description' => $request->description,
            'metadata' => $metadata,
        ];

        if (! $request->customerPresent) {
            $body['off_session'] = 'true';
        }

        try {
            return self::fromPaymentIntent($this->post('/payment_intents', $body, $request->idempotencyKey, cardErrorIsResult: true));
        } catch (StripeCardError $e) {
            return $e->result;
        }
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $body = ['payment_intent' => $request->paymentId, 'amount' => self::toStripeAmount($request->amount)];

        if ($request->reason !== null) {
            $body['metadata'] = ['reason' => $request->reason];
        }

        $refund = $this->post('/refunds', $body, $request->idempotencyKey);
        $status = (string) ($refund['status'] ?? '');

        // `pending` es normal en tarjetas: el reembolso ya está aceptado y se completa solo.
        return new RefundResult($this->string($refund, 'id'), ! in_array($status, ['failed', 'canceled'], true));
    }

    public function retrieve(string $gatewayPaymentId): ChargeResult
    {
        return self::fromPaymentIntent($this->get('/payment_intents/'.rawurlencode($gatewayPaymentId)));
    }

    public function findCharge(?string $customerId, string $idempotencyKey, int $sinceTimestamp): ?ChargeResult
    {
        if ($customerId === null || $customerId === '') {
            // Sin cliente no hay forma de acotar la búsqueda (listar TODOS los cobros de la cuenta mezclaría empresas e
            // instalaciones). Un cobro siempre se crea con `customer`, así que sin cliente nunca se envió ninguno.
            return null;
        }

        $cursor = null;

        for ($page = 0; $page < self::LOOKUP_PAGES; $page++) {
            $list = $this->list($sinceTimestamp, $cursor, 100, $customerId);

            foreach ($list['items'] as $item) {
                if ($item->idempotencyKey === $idempotencyKey) {
                    return $item;
                }
            }

            if ($list['next'] === null) {
                return null;   // lista completa recorrida: confirmado, no existe
            }

            $cursor = $list['next'];
        }

        // No se pudo recorrer todo: NO se puede afirmar que no existe.
        throw new TransientIntegrationError('No se pudo confirmar la ausencia del cobro (demasiados cobros recientes)', self::SERVICE);
    }

    public function cancelCharge(string $gatewayPaymentId): ChargeResult
    {
        try {
            return self::fromPaymentIntent($this->post('/payment_intents/'.rawurlencode($gatewayPaymentId).'/cancel', []));
        } catch (PermanentIntegrationError) {
            // Estado que ya no admite cancelar (p. ej. ya se cobró): se informa de su estado real.
            return $this->retrieve($gatewayPaymentId);
        }
    }

    public function recentCharges(int $sinceTimestamp, ?string $cursor = null, int $limit = 100): array
    {
        return $this->list($sinceTimestamp, $cursor, $limit, null);
    }

    /**
     * Importe de la app (unidad menor ISO 4217) -> `amount` de Stripe.
     *
     * @throws PermanentIntegrationError si Stripe no puede representar el importe (tres decimales que no acaban en 0)
     */
    public static function toStripeAmount(Money $money): int
    {
        $currency = strtoupper($money->currency);

        if (in_array($currency, self::THREE_DECIMAL, true) && $money->amountMinor % 10 !== 0) {
            throw new PermanentIntegrationError("stripe: un importe en {$currency} debe ser múltiplo de 10 en la unidad menor", self::SERVICE);
        }

        return $money->amountMinor * (self::STRIPE_FACTOR[$currency] ?? 1);
    }

    /** `amount` de Stripe (PaymentIntent, `amount_refunded`...) -> unidad menor ISO 4217 de la app. */
    public static function fromStripeAmount(int $amount, string $currency): int
    {
        return intdiv($amount, self::STRIPE_FACTOR[strtoupper($currency)] ?? 1);
    }

    /**
     * Convierte un PaymentIntent de Stripe (respuesta o `data.object` de un webhook) en el resultado neutro.
     *
     * @param  array<string, mixed>  $pi
     *
     * @throws InvalidOutputError
     */
    public static function fromPaymentIntent(array $pi): ChargeResult
    {
        $id = isset($pi['id']) && is_string($pi['id']) && $pi['id'] !== '' ? $pi['id'] : throw new InvalidOutputError('Stripe devolvió un PaymentIntent sin id', self::SERVICE);
        $status = (string) ($pi['status'] ?? '');
        /** @var array<string, string> $metadata */
        $metadata = array_map('strval', array_filter(is_array($pi['metadata'] ?? null) ? $pi['metadata'] : [], 'is_scalar'));

        $extras = [
            'customerId' => isset($pi['customer']) && is_string($pi['customer']) ? $pi['customer'] : null,
            'amountMinor' => isset($pi['amount'], $pi['currency']) ? self::fromStripeAmount((int) $pi['amount'], (string) $pi['currency']) : null,
            'currency' => isset($pi['currency']) ? strtoupper((string) $pi['currency']) : null,
            'idempotencyKey' => $metadata['idempotency_key'] ?? null,
            'metadata' => $metadata,
        ];

        return match ($status) {
            'succeeded' => new ChargeResult(true, $id, ...$extras),
            'requires_action' => new ChargeResult(false, $id, ...$extras, requiresAction: true, clientSecret: isset($pi['client_secret']) ? (string) $pi['client_secret'] : null),
            'processing' => new ChargeResult(false, $id, ...$extras, processing: true),
            'requires_payment_method' => self::failed($id, is_array($pi['last_payment_error'] ?? null) ? $pi['last_payment_error'] : [], $extras),
            'canceled' => new ChargeResult(false, $id, 'canceled', 'El cobro fue cancelado', ...$extras),
            default => throw new InvalidOutputError("Estado de PaymentIntent inesperado: {$status}", self::SERVICE),
        };
    }

    /**
     * @param  array<string, mixed>  $error  `error` de Stripe o `last_payment_error` de un PaymentIntent
     * @param  array<string, mixed>  $extras
     */
    private static function failed(string $id, array $error, array $extras): ChargeResult
    {
        $code = (string) ($error['decline_code'] ?? $error['code'] ?? 'payment_failed');

        return new ChargeResult(false, $id, $code, isset($error['message']) ? (string) $error['message'] : 'Cobro rechazado por la pasarela', ...$extras);
    }

    /**
     * @return array{items: list<ChargeResult>, next: string|null}
     */
    private function list(int $since, ?string $cursor, int $limit, ?string $customerId): array
    {
        $query = ['limit' => max(1, min(100, $limit)), 'created' => ['gte' => $since]];

        if ($customerId !== null) {
            $query['customer'] = $customerId;
        }

        if ($cursor !== null) {
            $query['starting_after'] = $cursor;
        }

        $body = $this->get('/payment_intents', $query);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $items = [];

        foreach ($data as $pi) {
            if (is_array($pi)) {
                try {
                    $items[] = self::fromPaymentIntent($pi);
                } catch (InvalidOutputError) {
                    // un PaymentIntent en un estado que no usamos (p. ej. requires_confirmation) no es nuestro cobro
                }
            }
        }

        $last = $data === [] ? null : end($data);
        $next = ($body['has_more'] ?? false) === true && is_array($last) && isset($last['id']) ? (string) $last['id'] : null;

        return ['items' => $items, 'next' => $next];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, ?string $idempotencyKey = null, bool $cardErrorIsResult = false): array
    {
        return $this->call(function () use ($path, $body, $idempotencyKey): Response {
            $client = $this->client();

            if ($idempotencyKey !== null) {
                $client = $client->withHeaders(['Idempotency-Key' => $this->keyPrefix().$idempotencyKey]);
            }

            return $client->asForm()->post($this->url($path), $body);
        }, $cardErrorIsResult);
    }

    /** Prefijo de la instalación para las claves de idempotencia (`services.stripe.key_prefix`); vacío = ninguno. */
    private function keyPrefix(): string
    {
        $prefix = (string) ($this->config['key_prefix'] ?? '');

        return $prefix === '' ? '' : $prefix.'-';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->call(fn (): Response => $this->client()->get($this->url($path), $query));
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     *
     * @throws StripeCardError|IntegrationException
     */
    private function call(callable $request, bool $cardErrorIsResult = false): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new TransientIntegrationError('Error de conexión con Stripe', self::SERVICE, $e);
        }

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : throw new InvalidOutputError('Stripe devolvió un cuerpo que no es JSON', self::SERVICE);
        }

        $error = $this->errorFor($response);

        // Solo un cobro trata el rechazo del banco como resultado; en cualquier otra operación es un error permanente.
        if ($error instanceof StripeCardError && ! $cardErrorIsResult) {
            throw new PermanentIntegrationError('stripe: operación rechazada por el banco ('.$error->result->failureCode.')', self::SERVICE);
        }

        throw $error;
    }

    private function errorFor(Response $response): IntegrationException|StripeCardError
    {
        $status = $response->status();
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $type = (string) ($error['type'] ?? '');

        if ($status === 402 && $type === 'card_error') {
            $pi = is_array($error['payment_intent'] ?? null) ? $error['payment_intent'] : [];
            $extras = [];

            if (isset($pi['metadata']) && is_array($pi['metadata'])) {
                /** @var array<string, string> $metadata */
                $metadata = array_map('strval', array_filter($pi['metadata'], 'is_scalar'));
                $extras = [
                    'customerId' => isset($pi['customer']) && is_string($pi['customer']) ? $pi['customer'] : null,
                    'amountMinor' => isset($pi['amount'], $pi['currency']) ? self::fromStripeAmount((int) $pi['amount'], (string) $pi['currency']) : null,
                    'currency' => isset($pi['currency']) ? strtoupper((string) $pi['currency']) : null,
                    'idempotencyKey' => $metadata['idempotency_key'] ?? null,
                    'metadata' => $metadata,
                ];
            }

            return new StripeCardError(self::failed(isset($pi['id']) ? (string) $pi['id'] : '', $error, $extras));
        }

        // Mensaje propio: solo tipo y código de Stripe (nunca el cuerpo, que puede llevar datos del cliente).
        $detail = trim($type.' '.($error['code'] ?? ''));

        if ($status === 409) {
            // Otra petición con la MISMA clave sigue en curso en Stripe: se reintenta con la misma clave.
            return new TransientIntegrationError("stripe: HTTP 409 {$detail}", self::SERVICE);
        }

        if ($type === 'idempotency_error') {
            return new PermanentIntegrationError('stripe: la clave de idempotencia se usó con otros parámetros', self::SERVICE);
        }

        $generic = IntegrationHttp::errorFor(self::SERVICE, $response);

        // Un 402 que no es de tarjeta no significa «sin cuota»: se trata como permanente con el detalle de Stripe.
        return $generic::class === QuotaExceededError::class
            ? new PermanentIntegrationError("stripe: HTTP {$status} {$detail}", self::SERVICE)
            : $generic;
    }

    private function client(): PendingRequest
    {
        $secret = $this->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw new PermanentIntegrationError('STRIPE_SECRET no está configurada', self::SERVICE);
        }

        $client = IntegrationHttp::client(self::SERVICE)->withToken($secret);

        $version = $this->config['api_version'] ?? null;

        return is_string($version) && $version !== '' ? $client->withHeaders(['Stripe-Version' => $version]) : $client;
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['api_base'] ?? 'https://api.stripe.com/v1'), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new InvalidOutputError("Stripe no devolvió «{$key}»", self::SERVICE);
    }
}
