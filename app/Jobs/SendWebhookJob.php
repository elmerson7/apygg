<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;

/**
 * SendWebhookJob
 *
 * Job para enviar webhooks a URLs externas.
 * Incluye reintentos exponenciales y manejo de fallos.
 */
class SendWebhookJob extends Job
{
    /**
     * ID del webhook a enviar
     */
    protected string $webhookId;

    /**
     * ID de la entrega a procesar
     */
    protected string $deliveryId;

    /**
     * Número máximo de intentos (configurable por webhook)
     * Se sobrescribe dinámicamente según el webhook
     */
    public int $tries = 3;

    /**
     * Backoff exponencial para reintentos
     * Se calcula dinámicamente según configuración
     */
    public array $backoff = [60, 120, 240]; // 1 min, 2 min, 4 min

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(string $webhookId, string $deliveryId)
    {
        parent::__construct();
        $this->webhookId = $webhookId;
        $this->deliveryId = $deliveryId;
    }

    /**
     * Ejecutar el job
     */
    protected function process(): void
    {
        $webhook = Webhook::find($this->webhookId);
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $webhook || ! $delivery) {
            throw new \RuntimeException("Webhook o entrega no encontrada: webhook_id={$this->webhookId}, delivery_id={$this->deliveryId}");
        }

        // Configurar tries según el webhook
        $this->tries = $webhook->max_retries ?? config('webhooks.defaults.max_retries', 3);

        // Verificar que el webhook esté activo
        if (! $webhook->isActive()) {
            $this->log('warning', 'Webhook inactivo, cancelando entrega', [
                'webhook_id' => $this->webhookId,
                'webhook_status' => $webhook->status,
            ]);

            $delivery->markAsFailed('Webhook inactivo');

            return;
        }

        // Verificar máximo de reintentos
        $maxRetries = $webhook->max_retries ?? config('webhooks.defaults.max_retries', 3);
        if ($delivery->attempts >= $maxRetries) {
            $this->log('warning', 'Máximo de reintentos alcanzado', [
                'webhook_id' => $this->webhookId,
                'delivery_id' => $this->deliveryId,
                'attempts' => $delivery->attempts,
                'max_retries' => $maxRetries,
            ]);

            $delivery->markAsFailed("Máximo de reintentos alcanzado ({$maxRetries})");

            return;
        }

        // Procesar entrega
        $webhookService = app(WebhookService::class);
        $success = $webhookService->processDelivery($webhook, $delivery);

        if (! $success && $delivery->attempts < $maxRetries) {
            // Si falló y aún hay reintentos disponibles, relanzar con delay exponencial
            // Laravel manejará el retry automáticamente usando el backoff configurado
            $delay = $this->calculateBackoffDelay($delivery->attempts);
            $this->log('info', "Reintentando webhook después de {$delay} segundos", [
                'webhook_id' => $this->webhookId,
                'delivery_id' => $this->deliveryId,
                'attempt' => $delivery->attempts,
                'delay' => $delay,
            ]);

            // Relanzar el job con delay
            self::dispatch($this->webhookId, $this->deliveryId)
                ->delay(now()->addSeconds($delay))
                ->onQueue(config('webhooks.queue.queue', 'webhooks'))
                ->onConnection(config('webhooks.queue.connection', 'redis'));
        }
    }

    /**
     * Calcular delay para backoff exponencial
     *
     * @return int Segundos de delay
     */
    protected function calculateBackoffDelay(int $attempts): int
    {
        $initialDelay = config('webhooks.retry.initial_delay', 60);
        $multiplier = config('webhooks.retry.backoff_multiplier', 2);
        $maxDelay = config('webhooks.retry.max_delay', 3600);

        $delay = $initialDelay * pow($multiplier, $attempts - 1);

        return min($delay, $maxDelay);
    }

    /**
     * Método llamado cuando el job falla definitivamente (dead letter queue)
     */
    public function failed(\Throwable $exception): void
    {
        parent::failed($exception);

        // Marcar entrega como fallida definitivamente
        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery) {
            $delivery->markAsFailed("Job falló definitivamente: {$exception->getMessage()}");
        }

        $this->log('critical', 'Webhook falló definitivamente después de todos los reintentos', [
            'webhook_id' => $this->webhookId,
            'delivery_id' => $this->deliveryId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }
}
