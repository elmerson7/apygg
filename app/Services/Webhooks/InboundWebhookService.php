<?php

namespace App\Services\Webhooks;

use App\Contracts\InboundWebhookServiceInterface;
use App\Contracts\InboundWebhookTenantResolverInterface;
use App\Contracts\InspectsWebhookSignatureInterface;
use App\Contracts\WebhookSignatureVerifierInterface;
use App\Exceptions\InboundWebhookException;
use App\Integrations\Webhooks\HmacSignatureVerifier;
use App\Jobs\ProcessInboundWebhookJob;
use App\Models\InboundWebhookReceipt;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Recibe webhooks de proveedores externos (docs/integrations.md §7).
 *
 * Orden: proveedor conocido -> secreto -> tamaño -> firma (cuerpo crudo, tiempo constante, ventana)
 * -> JSON -> reglas -> id de entrega -> tenant -> guardar (único por proveedor+entrega) -> encolar.
 * Se responde rápido; el trabajo lo hace `ProcessInboundWebhookJob`. Nunca se loguea el payload
 * ni el secreto: solo proveedor, id de entrega, IP y motivo.
 */
class InboundWebhookService implements InboundWebhookServiceInterface
{
    /** Cabeceras que jamás se guardan aunque el proveedor las liste. */
    private const SENSITIVE_HEADER = '/signature|secret|token|authorization|cookie|key/i';

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function receive(string $provider, Request $request): array
    {
        /** @var array<string, mixed>|null $config */
        $config = config("integrations.inbound_webhooks.providers.{$provider}");

        if (! is_array($config)) {
            throw InboundWebhookException::unknownProvider();
        }

        $secret = $this->secretFor($config);

        if ($secret === null) {
            Log::error('Webhook entrante: secreto no configurado', ['provider' => $provider]);

            throw InboundWebhookException::notConfigured();
        }

        $this->guardSize($provider, $request, $config);
        $this->guardSignature($provider, $request, $config, $secret);

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            throw InboundWebhookException::malformed();
        }

        if (is_array($config['rules'] ?? null) && $config['rules'] !== []) {
            $validator = Validator::make($payload, $config['rules']);

            if ($validator->fails()) {
                Log::warning('Webhook entrante: payload inválido', ['provider' => $provider, 'fields' => array_keys($validator->errors()->toArray())]);

                throw InboundWebhookException::invalidPayload($validator->errors()->toArray());
            }
        }

        $deliveryId = $this->deliveryId($request, $payload, $config);
        $tenantId = $this->resolveTenant($request, $payload, $config);

        foreach ((array) ($config['strip_payload'] ?? []) as $path) {
            // Secretos del proveedor que el handler no necesita (p. ej. el `client_secret` de Stripe): no llegan a la BD.
            data_forget($payload, (string) $path);
        }

        $receipt = InboundWebhookReceipt::recordOnce([
            'provider' => $provider,
            'delivery_id' => $deliveryId,
            'tenant_id' => $tenantId,
            'payload' => $payload,
            'headers' => $this->storedHeaders($request, $config),
        ]);

        if ($receipt === null) {
            $existing = InboundWebhookReceipt::query()->where('provider', $provider)->where('delivery_id', $deliveryId)->firstOrFail();

            return ['status' => 'duplicate', 'receipt_id' => $existing->id];
        }

        $this->dispatch($receipt);

        return ['status' => 'received', 'receipt_id' => $receipt->id];
    }

    public function sweep(int $stuckMinutes = 10): array
    {
        $redispatched = 0;

        InboundWebhookReceipt::query()
            ->where('status', InboundWebhookReceipt::STATUS_RECEIVED)
            ->where('created_at', '<', now()->subMinutes($stuckMinutes))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (InboundWebhookReceipt $receipt) use (&$redispatched) {
                $this->dispatch($receipt);
                $redispatched++;
            });

        $redispatched += $this->retryFailed();

        $pruned = InboundWebhookReceipt::query()
            ->whereIn('status', [InboundWebhookReceipt::STATUS_PROCESSED, InboundWebhookReceipt::STATUS_FAILED])
            ->where('created_at', '<', now()->subDays((int) config('integrations.inbound_webhooks.retention_days', 30)))
            ->delete();

        return ['redispatched' => $redispatched, 'pruned' => $pruned];
    }

    /**
     * Recibos `failed` tras agotar los reintentos del job (p. ej. un reembolso que llegó antes que el cobro reflejado, o un
     * fallo transitorio largo de la pasarela): se reencolan con un backoff largo y creciente (`after_minutes` x 2^k hasta
     * `max_minutes`) hasta `max_attempts` intentos en total. El último intento deja un error en el log para que alguien mire.
     * Los fallos permanentes (`PermanentIntegrationError`) no se reintentan: no cambiarían. El handler es idempotente.
     */
    private function retryFailed(): int
    {
        $after = max(1, (int) config('integrations.inbound_webhooks.sweep_failed.after_minutes', 60));
        $cap = max($after, (int) config('integrations.inbound_webhooks.sweep_failed.max_minutes', 1440));
        $maxAttempts = (int) config('integrations.inbound_webhooks.sweep_failed.max_attempts', 20);
        $tries = max(1, (int) config('integrations.inbound_webhooks.tries', 5));
        $count = 0;

        InboundWebhookReceipt::query()
            ->where('status', InboundWebhookReceipt::STATUS_FAILED)
            ->where('attempts', '<', $maxAttempts)
            ->where('updated_at', '<', now()->subMinutes($after))
            ->where(fn ($q) => $q->whereNull('error')->orWhere('error', 'not like', 'PermanentIntegrationError%'))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (InboundWebhookReceipt $receipt) use (&$count, $after, $cap, $maxAttempts, $tries) {
                $wait = min($cap, $after * (2 ** min(10, max(0, $receipt->attempts - $tries))));

                if ($receipt->updated_at === null || $receipt->updated_at->gt(now()->subMinutes($wait))) {
                    return;
                }

                if ($receipt->attempts + 1 >= $maxAttempts) {
                    Log::error('Webhook entrante: último reintento del barrido; si vuelve a fallar queda para revisión manual', ['provider' => $receipt->provider, 'receipt_id' => $receipt->id, 'attempts' => $receipt->attempts]);
                }

                $this->dispatch($receipt);
                $count++;
            });

        return $count;
    }

    /**
     * Un fallo de la cola (Redis caído) NO pierde el webhook: el recibo ya está guardado y `sweep()` lo reencola.
     */
    private function dispatch(InboundWebhookReceipt $receipt): void
    {
        try {
            ProcessInboundWebhookJob::dispatch($receipt->id)->onQueue((string) config('integrations.inbound_webhooks.queue', 'default'));
        } catch (Throwable $e) {
            Log::error('Webhook entrante: no se pudo encolar el recibo', [
                'provider' => $receipt->provider,
                'receipt_id' => $receipt->id,
                'error' => class_basename($e),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function secretFor(array $config): ?string
    {
        $secret = isset($config['secret_config']) ? config((string) $config['secret_config']) : ($config['secret'] ?? null);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function guardSize(string $provider, Request $request, array $config): void
    {
        $max = (int) ($config['max_bytes'] ?? config('integrations.inbound_webhooks.max_bytes', 1048576));
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > $max || strlen($request->getContent()) > $max) {
            Log::warning('Webhook entrante: cuerpo demasiado grande', ['provider' => $provider, 'ip' => $request->ip()]);

            throw InboundWebhookException::tooLarge();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function guardSignature(string $provider, Request $request, array $config, string $secret): void
    {
        $verifier = $this->verifierFor((array) ($config['signature'] ?? ['driver' => 'svix']));

        $reason = $verifier instanceof InspectsWebhookSignatureInterface
            ? $verifier->inspect($request, $secret)
            : ($verifier->verify($request, $secret) ? null : InspectsWebhookSignatureInterface::INVALID);

        if ($reason === null) {
            return;
        }

        Log::warning('Webhook entrante: firma rechazada', ['provider' => $provider, 'reason' => $reason, 'ip' => $request->ip()]);

        throw match ($reason) {
            InspectsWebhookSignatureInterface::MISSING => InboundWebhookException::signatureMissing(),
            InspectsWebhookSignatureInterface::EXPIRED => InboundWebhookException::timestampExpired(),
            default => InboundWebhookException::signatureInvalid(),
        };
    }

    /**
     * @param  array<string, mixed>  $signature
     */
    private function verifierFor(array $signature): WebhookSignatureVerifierInterface
    {
        $driver = (string) ($signature['driver'] ?? 'svix');

        if ($driver === 'hmac') {
            return new HmacSignatureVerifier($signature);
        }

        if ($driver === 'svix') {
            return app(WebhookSignatureVerifierInterface::class);
        }

        $custom = app($driver);

        return $custom instanceof WebhookSignatureVerifierInterface
            ? $custom
            : throw InboundWebhookException::notConfigured();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function deliveryId(Request $request, array $payload, array $config): string
    {
        $source = (array) ($config['delivery_id'] ?? []);

        $id = match (true) {
            isset($source['header']) => $request->header((string) $source['header']),
            isset($source['payload']) => data_get($payload, (string) $source['payload']),
            default => 'sha256:'.hash('sha256', $request->getContent()),
        };

        if (! is_scalar($id) || trim((string) $id) === '' || strlen((string) $id) > 191) {
            throw InboundWebhookException::malformed('Falta el identificador de la entrega');
        }

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function resolveTenant(Request $request, array $payload, array $config): ?int
    {
        if (! $this->tenantContext->enabled() || empty($config['tenant_resolver'])) {
            return null;
        }

        $resolver = app((string) $config['tenant_resolver']);
        $id = $resolver instanceof InboundWebhookTenantResolverInterface ? $resolver->resolve($request, $payload) : null;

        if ($id === null) {
            if (! empty($config['tenant_required'])) {
                throw InboundWebhookException::invalidTenant();
            }

            return null;
        }

        if (! Tenant::query()->whereKey($id)->exists()) {
            throw InboundWebhookException::invalidTenant();
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>|null
     */
    private function storedHeaders(Request $request, array $config): ?array
    {
        $stored = [];

        foreach ((array) ($config['store_headers'] ?? []) as $name) {
            $name = (string) $name;
            $value = $request->header($name);

            if (is_string($value) && ! preg_match(self::SENSITIVE_HEADER, $name)) {
                $stored[strtolower($name)] = mb_substr($value, 0, 255);
            }
        }

        return $stored === [] ? null : $stored;
    }
}
