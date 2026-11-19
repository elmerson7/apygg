<?php

namespace App\Jobs;

use App\Contracts\WebhookServiceInterface;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;

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
    protected int|string $webhookId;

    /**
     * ID de la entrega a procesar
     */
    protected int|string $deliveryId;

    /**
     * Tenant con el que se encoló (multiempresa). Además de viajar en el `Context` de Laravel, va en
     * el propio job: al ejecutarse se comprueba que el contexto restaurado es ESE tenant.
     */
    protected ?int $tenantId = null;

    /**
     * Un solo mecanismo de reintento: el REDESPACHO propio (con delay exponencial), porque
     * el máximo de intentos es por webhook (`max_retries`) y se cuenta en la entrega
     * (`WebhookDelivery::attempts`), no en el job. Por eso Laravel no reintenta este job
     * (`$tries = 1`, sin `backoff`): cada ejecución es un intento y decide si encola otro.
     */
    public int $tries = 1;

    /**
     * @var array<int>
     */
    public array $backoff = [];

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(int|string $webhookId, int|string $deliveryId)
    {
        parent::__construct();
        $this->timeout = (int) config('webhooks.job_timeout', 150);
        $this->webhookId = $webhookId;
        $this->deliveryId = $deliveryId;
        $this->tenantId = app(TenantContext::class)->id();
    }

    /**
     * Ejecutar el job
     */
    public function process(WebhookServiceInterface $webhookService): void
    {
        $this->inTenant(fn () => $this->deliver($webhookService));
    }

    /**
     * Ejecuta el callback en el tenant del job (multiempresa). Con multiempresa apagado, tal cual.
     * Falla cerrado: sin tenant guardado, o con un contexto restaurado distinto, no entrega nada.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function inTenant(\Closure $callback): mixed
    {
        $context = app(TenantContext::class);

        if (! $context->enabled()) {
            return $callback();
        }

        if ($this->tenantId === null) {
            throw MissingTenantContext::forModel(Webhook::class, 'job');
        }

        if ($context->id() === $this->tenantId) {
            return $callback();
        }

        // El contexto no es el del job (p. ej. ya se limpió tras un fallo): se restaura desde el job.
        if ($context->id() !== null) {
            throw CrossTenantViolation::foreignRecord(Webhook::class);
        }

        return $context->run(Tenant::query()->findOrFail($this->tenantId), $callback);
    }

    protected function deliver(WebhookServiceInterface $webhookService): void
    {
        $webhook = Webhook::find($this->webhookId);
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $webhook || ! $delivery) {
            throw new \RuntimeException("Webhook o entrega no encontrada: webhook_id={$this->webhookId}, delivery_id={$this->deliveryId}");
        }

        // Idempotencia: una entrega ya realizada no se vuelve a enviar (reintento duplicado)
        if ($delivery->isSuccessful()) {
            return;
        }

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
        $success = $webhookService->processDelivery($webhook, $delivery);

        if ($success) {
            return;
        }

        // Error permanente: el destino rechazó la petición por algo nuestro (4xx salvo
        // 408/429). Reintentar solo repetiría el mismo rechazo.
        if ($this->isPermanentFailure($delivery->response_code)) {
            $this->log('warning', 'Webhook rechazado por el destino (4xx): sin reintento', [
                'webhook_id' => $this->webhookId,
                'delivery_id' => $this->deliveryId,
                'response_code' => $delivery->response_code,
            ]);

            return;
        }

        // 5xx, 408, 429, timeout o error de conexión: reintento con backoff exponencial
        if ($delivery->attempts < $maxRetries) {
            $delay = $this->calculateBackoffDelay($delivery->attempts);
            $this->log('info', "Reintentando webhook después de {$delay} segundos", [
                'webhook_id' => $this->webhookId,
                'delivery_id' => $this->deliveryId,
                'attempt' => $delivery->attempts,
                'delay' => $delay,
            ]);

            self::dispatch($this->webhookId, $this->deliveryId)
                ->delay(now()->addSeconds($delay))
                ->onQueue(config('webhooks.queue.queue', 'webhooks'))
                ->onConnection(config('webhooks.queue.connection', 'redis'));
        }
    }

    /**
     * ¿Un código HTTP del destino indica un fallo que no se arregla reintentando?
     */
    protected function isPermanentFailure(?int $responseCode): bool
    {
        return $responseCode !== null
            && $responseCode >= 400
            && $responseCode < 500
            && ! in_array($responseCode, [408, 429], true);
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

        // Marcar entrega como fallida definitivamente (en el tenant del job: el contexto ya se limpió)
        try {
            $this->inTenant(function () use ($exception) {
                $delivery = WebhookDelivery::find($this->deliveryId);
                if ($delivery) {
                    $delivery->markAsFailed("Job falló definitivamente: {$exception->getMessage()}");
                }
            });
        } catch (\Throwable $e) {
            $this->log('error', 'No se pudo marcar la entrega como fallida', ['error' => $e->getMessage()]);
        }

        $this->log('critical', 'Webhook falló definitivamente después de todos los reintentos', [
            'webhook_id' => $this->webhookId,
            'delivery_id' => $this->deliveryId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }
}
