<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;

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
            ->onConnection(config('webhooks.queue.connection', 'redis'));

        return $delivery;
    }

    /**
     * Procesar entrega de webhook (llamado desde el Job)
     */
    public function processDelivery(Webhook $webhook, WebhookDelivery $delivery): bool
    {
        // Marcar como procesando
        $delivery->update(['status' => WebhookDelivery::STATUS_PROCESSING]);
        $delivery->incrementAttempts();

        try {
            // Preparar payload con metadatos
            $payload = $this->preparePayload($delivery->payload, $webhook, $delivery);

            // Generar firma HMAC usando el secret actual
            // Durante período de gracia después de rotación, siempre usar el nuevo secret
            $signature = $this->generateSignature($payload, $webhook->secret ?? '');

            // Preparar headers
            $headers = $this->prepareHeaders($signature, $webhook);

            // Enviar HTTP request
            $response = Http::timeout($webhook->timeout ?? config('webhooks.defaults.timeout', 30))
                ->withHeaders($headers)
                ->post($webhook->url, $payload);

            // Verificar respuesta
            if ($response->successful()) {
                // Marcar como exitosa
                $delivery->markAsSuccessful($response->status(), $response->body());
                $webhook->incrementSuccess();
                $webhook->updateLastTriggered();

                LogService::info('Webhook entregado exitosamente', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => $delivery->event_type,
                    'response_code' => $response->status(),
                ]);

                return true;
            } else {
                // Marcar como fallida
                $errorMessage = "HTTP {$response->status()}: {$response->body()}";
                $delivery->markAsFailed($errorMessage, $response->status());
                $webhook->incrementFailure();
                $webhook->updateLastTriggered();

                LogService::warning('Webhook falló', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => $delivery->event_type,
                    'response_code' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return false;
            }
        } catch (\Exception $e) {
            // Marcar como fallida por excepción
            $delivery->markAsFailed($e->getMessage());
            $webhook->incrementFailure();
            $webhook->updateLastTriggered();

            LogService::error('Error al procesar webhook', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
                'event_type' => $delivery->event_type,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return false;
        }
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
     * Generar firma HMAC-SHA256 del payload
     */
    protected function generateSignature(array $payload, string $secret): string
    {
        if (empty($secret)) {
            return '';
        }

        $algorithm = config('webhooks.security.algorithm', 'sha256');
        $payloadString = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac($algorithm, $payloadString, $secret);
    }

    /**
     * Validar firma HMAC de un webhook recibido
     * Útil para validar webhooks entrantes de otros servicios
     *
     * @param  array  $payload  Payload recibido
     * @param  string  $signature  Firma recibida en el header
     * @param  string  $secret  Secret para validar
     */
    public function validateSignature(array $payload, string $signature, string $secret): bool
    {
        if (empty($secret) || empty($signature)) {
            return false;
        }

        $expectedSignature = $this->generateSignature($payload, $secret);

        // Usar hash_equals para prevenir timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Validar timestamp de un webhook recibido
     * Previene replay attacks rechazando webhooks muy antiguos
     *
     * @param  int  $timestamp  Timestamp recibido en el header
     * @param  int|null  $tolerance  Tolerancia en segundos (default: config)
     */
    public function validateTimestamp(int $timestamp, ?int $tolerance = null): bool
    {
        $tolerance = $tolerance ?? config('webhooks.security.timestamp_tolerance', 300);
        $now = now()->timestamp;

        // Verificar que el timestamp no sea muy antiguo ni muy futuro
        $diff = abs($now - $timestamp);

        return $diff <= $tolerance;
    }

    /**
     * Validar webhook completo (firma + timestamp)
     * Útil para validar webhooks entrantes de otros servicios
     *
     * @param  array  $payload  Payload recibido
     * @param  string  $signature  Firma recibida en el header
     * @param  int  $timestamp  Timestamp recibido en el header
     * @param  string  $secret  Secret para validar
     * @param  string|null  $oldSecret  Secret anterior (opcional, para rotación)
     */
    public function validateWebhook(
        array $payload,
        string $signature,
        int $timestamp,
        string $secret,
        ?string $oldSecret = null
    ): bool {
        // Validar timestamp primero (más rápido)
        if (! $this->validateTimestamp($timestamp)) {
            return false;
        }

        // Validar firma con secret actual
        if ($this->validateSignature($payload, $signature, $secret)) {
            return true;
        }

        // Si hay secret anterior y aún es válido, intentar validar con él
        if (! empty($oldSecret)) {
            return $this->validateSignature($payload, $signature, $oldSecret);
        }

        return false;
    }

    /**
     * Preparar headers HTTP para el webhook
     */
    protected function prepareHeaders(string $signature, Webhook $webhook): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'APYGG-Webhook/1.0',
            'X-Webhook-Id' => $webhook->id,
        ];

        // Agregar firma si existe
        if (! empty($signature)) {
            $signatureHeader = config('webhooks.security.signature_header', 'X-Webhook-Signature');
            $headers[$signatureHeader] = $signature;
        }

        // Agregar timestamp
        $timestampHeader = config('webhooks.security.timestamp_header', 'X-Webhook-Timestamp');
        $headers[$timestampHeader] = now()->timestamp;

        return $headers;
    }

    /**
     * Obtener webhooks activos que escuchan un evento específico
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Webhook>
     */
    public function getWebhooksForEvent(string $eventType): \Illuminate\Database\Eloquent\Collection
    {
        return Webhook::forEvent($eventType)->get();
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
