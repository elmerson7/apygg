<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WebhookService
 *
 * Servicio para gestión de webhooks:
 * - Entrega de webhooks a URLs externas
 * - Tracking de entregas
 * - Generación de firmas HMAC
 * - Manejo de reintentos
 */
class WebhookService
{
    /**
     * Enviar webhook a una URL específica
     *
     * @param  Webhook  $webhook  Webhook a enviar
     * @param  string  $eventType  Tipo de evento (ej: 'user.created')
     * @param  array  $payload  Datos del evento
     */
    public function send(Webhook $webhook, string $eventType, array $payload): WebhookDelivery
    {
        // Crear registro de entrega
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        // Despachar job para enviar webhook
        SendWebhookJob::dispatch($webhook->id, $delivery->id)
            ->onQueue(config('webhooks.queue.queue', 'webhooks'))
            ->onConnection(config('webhooks.queue.connection', 'redis'))
            ->afterCommit();

        return $delivery;
    }

    /**
     * Procesar entrega de webhook (llamado desde el Job).
     *
     * Idempotente: la entrega se "reclama" con un UPDATE atómico; si otra ejecución (un
     * reintento automático y uno manual a la vez) ya la tomó o ya se entregó, no se vuelve
     * a enviar. El receptor puede además deduplicar por `event.id`.
     */
    public function processDelivery(Webhook $webhook, WebhookDelivery $delivery): bool
    {
        if (! $delivery->claim()) {
            LogService::info('Entrega de webhook omitida: ya procesada o en curso', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
            ]);

            return $delivery->isSuccessful();
        }

        $delivery->incrementAttempts();

        try {
            // SSRF: se vuelve a validar y resolver el destino justo antes de enviar
            $target = WebhookUrlGuard::inspect($webhook->url);

            // Se serializa UNA vez: lo que se firma es exactamente lo que se envía
            $body = json_encode(
                $this->preparePayload($delivery->payload, $webhook, $delivery),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $timestamp = now()->timestamp;

            $request = Http::timeout($webhook->timeout ?? config('webhooks.defaults.timeout', 30))
                ->withoutRedirecting()
                ->withHeaders($this->prepareHeaders($body, $timestamp, $webhook))
                ->withBody($body, 'application/json');

            // Fija la IP validada: un DNS-rebinding no puede cambiarla antes de conectar
            if (! $target['is_literal']) {
                $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
                $request = $request->withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
                ]);
            }

            $response = $request->send('POST', $webhook->url);
            $excerpt = Str::limit($response->body(), (int) config('webhooks.max_response_body', 1000), '…');

            if ($response->successful()) {
                DB::transaction(function () use ($delivery, $webhook, $response, $excerpt) {
                    $delivery->markAsSuccessful($response->status(), $excerpt);
                    $webhook->incrementSuccess();
                });

                LogService::info('Webhook entregado exitosamente', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => $delivery->event_type,
                    'response_code' => $response->status(),
                ]);

                return true;
            }

            $errorMessage = "HTTP {$response->status()}: {$excerpt}";

            return $this->recordFailure($webhook, $delivery, $errorMessage, $response->status());
        } catch (\Throwable $e) {
            LogService::error('Error al procesar webhook', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
                'event_type' => $delivery->event_type,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return $this->recordFailure($webhook, $delivery, Str::limit($e->getMessage(), 500, '…'));
        }
    }

    protected function recordFailure(Webhook $webhook, WebhookDelivery $delivery, string $message, ?int $status = null): bool
    {
        DB::transaction(function () use ($delivery, $webhook, $message, $status) {
            $delivery->markAsFailed($message, $status);
            $webhook->incrementFailure();
        });

        LogService::warning('Webhook falló', [
            'webhook_id' => $webhook->id,
            'delivery_id' => $delivery->id,
            'event_type' => $delivery->event_type,
            'response_code' => $status,
            'error' => $message,
        ]);

        return false;
    }

    /**
     * Preparar payload con metadatos adicionales
     *
     * @param  array  $payload  Payload original
     */
    protected function preparePayload(array $payload, Webhook $webhook, WebhookDelivery $delivery): array
    {
        return array_merge($payload, [
            'webhook' => [
                'id' => $webhook->id,
                'name' => $webhook->name,
            ],
            'event' => [
                'type' => $delivery->event_type,
                'id' => $delivery->id,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Firma HMAC de un webhook: `sha256=` + HMAC(secreto, "{timestamp}.{cuerpo}").
     * El timestamp va firmado, así un payload capturado no se puede reenviar más tarde.
     *
     * @param  string  $body  Cuerpo crudo tal como se envía
     */
    public function sign(int $timestamp, string $body, string $secret): string
    {
        $algorithm = config('webhooks.security.algorithm', 'sha256');

        return $algorithm.'='.hash_hmac($algorithm, "{$timestamp}.{$body}", $secret);
    }

    /**
     * Verificar un webhook recibido (referencia para el receptor y para los tests).
     *
     * @param  string  $body  Cuerpo CRUDO de la petición (no el JSON re-serializado)
     * @param  string  $signatureHeader  Valor de `X-Webhook-Signature` (una o varias firmas separadas por espacio)
     * @param  int  $timestamp  Valor de `X-Webhook-Timestamp`
     * @param  string|null  $oldSecret  Secreto anterior (durante la rotación)
     */
    public function verify(string $body, string $signatureHeader, int $timestamp, string $secret, ?string $oldSecret = null, ?int $tolerance = null): bool
    {
        $tolerance = $tolerance ?? (int) config('webhooks.security.timestamp_tolerance', 300);

        if ($secret === '' || $signatureHeader === '' || abs(now()->timestamp - $timestamp) > $tolerance) {
            return false;
        }

        $received = preg_split('/\s+/', trim($signatureHeader)) ?: [];

        foreach (array_filter([$secret, $oldSecret]) as $candidate) {
            $expected = $this->sign($timestamp, $body, $candidate);
            foreach ($received as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cabeceras HTTP del webhook (firma incluida).
     *
     * Durante la ventana de rotación se envían DOS firmas (nuevo y anterior secreto) en
     * la misma cabecera, para que los receptores no se corten mientras migran.
     */
    protected function prepareHeaders(string $body, int $timestamp, Webhook $webhook): array
    {
        $headers = [
            'User-Agent' => 'APYGG-Webhook/1.0',
            'X-Webhook-Id' => $webhook->id,
            config('webhooks.security.timestamp_header', 'X-Webhook-Timestamp') => $timestamp,
        ];

        if (! empty($webhook->secret)) {
            $signatures = [$this->sign($timestamp, $body, $webhook->secret)];

            if ($webhook->isOldSecretValid((int) config('webhooks.security.rotation_grace_days', 7))) {
                $signatures[] = $this->sign($timestamp, $body, $webhook->old_secret);
            }

            $headers[config('webhooks.security.signature_header', 'X-Webhook-Signature')] = implode(' ', $signatures);
        }

        return $headers;
    }

    /**
     * Obtener webhooks activos de UN dueño que escuchan un evento específico.
     * Un webhook nunca recibe eventos de recursos que no son de su dueño.
     *
     * @return Collection<int, Webhook>
     */
    public function getWebhooksForEvent(string $eventType, int|string $ownerId): Collection
    {
        return Webhook::forEvent($eventType)->byUser((string) $ownerId)->get();
    }

    /**
     * Reintentar entrega fallida
     */
    public function retryDelivery(WebhookDelivery $delivery): bool
    {
        /** @var Webhook|null $webhook */
        $webhook = $delivery->webhook;

        if (! $webhook instanceof Webhook || ! $webhook->isActive()) {
            return false;
        }

        // Verificar si se excedió el máximo de reintentos
        if ($delivery->attempts >= ($webhook->max_retries ?? config('webhooks.defaults.max_retries', 3))) {
            return false;
        }

        // Despachar job para reintentar
        SendWebhookJob::dispatch($webhook->id, $delivery->id)
            ->onQueue(config('webhooks.queue.queue', 'webhooks'))
            ->onConnection(config('webhooks.queue.connection', 'redis'))
            ->delay($this->calculateRetryDelay($delivery->attempts));

        return true;
    }

    /**
     * Calcular delay para reintento usando backoff exponencial
     *
     * @return int Segundos de delay
     */
    protected function calculateRetryDelay(int $attempts): int
    {
        $initialDelay = config('webhooks.retry.initial_delay', 60);
        $multiplier = config('webhooks.retry.backoff_multiplier', 2);
        $maxDelay = config('webhooks.retry.max_delay', 3600);

        $delay = $initialDelay * pow($multiplier, $attempts - 1);

        return min($delay, $maxDelay);
    }
}
