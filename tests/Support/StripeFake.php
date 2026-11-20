<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Simulador mínimo de la API de Stripe para `Http::fake` (sin red): clientes, PaymentMethods, PaymentIntents con
 * idempotencia por clave (que se puede «caducar» como a las 24 h), 3DS, rechazos, listado, cancelación y reembolsos.
 * Tarjetas: `pm_card_ok` cobra; `pm_card_declined` rechaza; `pm_card_3ds` pide 3DS (sin cliente presente, `authentication_required`).
 */
class StripeFake
{
    /** @var array<string, array<string, mixed>> PaymentIntents por id */
    public array $intents = [];

    /** @var array<string, array{status: int, body: array<string, mixed>}> respuesta guardada por clave (se «olvida» con expireKeys) */
    private array $keys = [];

    /** @var list<array{method: string, path: string, key: string|null, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $refunds = [];

    public int $loseResponses = 0;

    public bool $failListing = false;

    private int $n = 0;

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $r) => $fake->handle($r));

        return $fake;
    }

    /** Las próximas `$times` creaciones de PaymentIntent se COBRAN en Stripe pero la respuesta se pierde (timeout). */
    public function loseNext(int $times = 1): static
    {
        $this->loseResponses += $times;

        return $this;
    }

    /** Pasadas 24 h Stripe olvida las claves de idempotencia: reenviar la misma clave crearía OTRO cobro. */
    public function expireKeys(): static
    {
        $this->keys = [];

        return $this;
    }

    /** El cliente confirma el 3DS. */
    public function confirm(string $id): static
    {
        $this->intents[$id]['status'] = 'succeeded';

        return $this;
    }

    /** @return array<string, mixed> el PaymentIntent tal como lo enviaría Stripe en un webhook */
    public function intent(string $id): array
    {
        return $this->intents[$id];
    }

    /** Un cobro que existe en Stripe sin intento local (p. ej. se perdió todo rastro). */
    public function injectSucceeded(string $customer, int $amount, string $currency, array $metadata): string
    {
        $id = 'pi_'.++$this->n;
        $this->intents[$id] = $this->base($id, $amount, $currency, $customer, $metadata) + ['status' => 'succeeded'];

        return $id;
    }

    /** @return list<string> ids de los PaymentIntents que cobraron */
    public function succeeded(): array
    {
        return array_keys(array_filter($this->intents, fn ($i) => $i['status'] === 'succeeded'));
    }

    /** @return list<string> */
    public function intentIdsFor(string $key): array
    {
        return array_keys(array_filter($this->intents, fn ($i) => ($i['metadata']['idempotency_key'] ?? null) === $key));
    }

    public function handle(Request $request): Response|PromiseInterface
    {
        $url = parse_url($request->url());
        $path = (string) preg_replace('#^/v1#', '', (string) $url['path']);
        $method = $request->method();
        parse_str($method === 'GET' ? (string) ($url['query'] ?? '') : $request->body(), $body);
        $key = $request->header('Idempotency-Key')[0] ?? null;
        $this->requests[] = ['method' => $method, 'path' => $path, 'key' => $key, 'body' => $body];

        if ($key !== null && isset($this->keys[$key])) {
            return Http::response($this->keys[$key]['body'], $this->keys[$key]['status']);
        }

        [$status, $json] = $this->route($method, $path, $body);

        if ($key !== null) {
            $this->keys[$key] = ['status' => $status, 'body' => $json];
        }

        if ($method === 'POST' && $path === '/payment_intents' && $this->loseResponses > 0) {
            $this->loseResponses--;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response($json, $status);
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function route(string $method, string $path, array $b): array
    {
        if ($method === 'POST' && $path === '/customers') {
            return [200, ['id' => 'cus_'.++$this->n]];
        }

        if ($method === 'POST' && preg_match('#^/payment_methods/(pm_\w+)/attach$#', $path, $m)) {
            return [200, ['id' => $m[1], 'card' => ['brand' => 'visa', 'last4' => '4242']]];
        }

        if ($method === 'POST' && $path === '/payment_intents') {
            return $this->createIntent($b);
        }

        if ($method === 'GET' && $path === '/payment_intents') {
            return $this->listIntents($b);
        }

        if ($method === 'GET' && preg_match('#^/payment_intents/(pi_\w+)$#', $path, $m)) {
            return isset($this->intents[$m[1]]) ? [200, $this->intents[$m[1]]] : [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']]];
        }

        if ($method === 'POST' && preg_match('#^/payment_intents/(pi_\w+)/cancel$#', $path, $m)) {
            $i = &$this->intents[$m[1]];

            if (! in_array($i['status'], ['requires_action', 'requires_payment_method'], true)) {
                return [400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state']]];
            }

            $i['status'] = 'canceled';

            return [200, $i];
        }

        if ($method === 'POST' && $path === '/refunds') {
            $this->refunds[] = $b;

            return [200, ['id' => 're_'.++$this->n, 'status' => 'succeeded']];
        }

        return [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'unknown_route']]];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createIntent(array $b): array
    {
        $id = 'pi_'.++$this->n;
        $pi = $this->base($id, (int) $b['amount'], (string) $b['currency'], (string) $b['customer'], (array) ($b['metadata'] ?? []));
        $offSession = ($b['off_session'] ?? 'false') === 'true';

        $declined = fn (string $code, ?string $decline) => [402, ['error' => [
            'type' => 'card_error', 'code' => $code, 'decline_code' => $decline, 'message' => 'Tu tarjeta fue rechazada',
            'payment_intent' => $this->intents[$id] = $pi + ['status' => 'requires_payment_method', 'last_payment_error' => ['code' => $code, 'decline_code' => $decline, 'message' => 'Tu tarjeta fue rechazada']],
        ]]];

        return match ($b['payment_method'] ?? '') {
            'pm_card_declined' => $declined('card_declined', 'insufficient_funds'),
            'pm_card_3ds' => $offSession ? $declined('authentication_required', null) : [200, $this->intents[$id] = $pi + ['status' => 'requires_action']],
            default => [200, $this->intents[$id] = $pi + ['status' => 'succeeded']],
        };
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function listIntents(array $q): array
    {
        if ($this->failListing) {
            return [500, ['error' => ['type' => 'api_error']]];
        }

        $items = array_reverse(array_values($this->intents));
        $items = array_values(array_filter($items, fn ($i) => $i['created'] >= (int) ($q['created']['gte'] ?? 0) && (! isset($q['customer']) || $i['customer'] === $q['customer'])));

        if (isset($q['starting_after'])) {
            $ids = array_column($items, 'id');
            $items = array_slice($items, (int) array_search($q['starting_after'], $ids, true) + 1);
        }

        $limit = (int) ($q['limit'] ?? 10);

        return [200, ['data' => array_slice($items, 0, $limit), 'has_more' => count($items) > $limit]];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function base(string $id, int $amount, string $currency, string $customer, array $metadata): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'amount' => $amount, 'currency' => $currency, 'customer' => $customer, 'metadata' => $metadata, 'client_secret' => "{$id}_secret_abc123", 'created' => now()->getTimestamp()];
    }
}
