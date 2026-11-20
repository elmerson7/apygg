<?php

namespace App\Jobs;

use App\Contracts\InboundWebhookHandlerInterface;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Models\InboundWebhookReceipt;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Procesa un recibo de webhook entrante con el handler registrado para su proveedor.
 *
 * - Reclama el intento con un UPDATE atómico: un recibo ya procesado no se repite.
 * - Corre en el tenant del recibo (si lo tiene y la multiempresa está activa).
 * - Fallo del handler: recibo `failed` + excepción relanzada, la cola reintenta con backoff
 *   (`integrations.inbound_webhooks.tries/backoff`). `PermanentIntegrationError`: `failed` sin reintento.
 */
class ProcessInboundWebhookJob extends Job
{
    public function __construct(protected int $receiptId)
    {
        parent::__construct();

        $this->tries = max(1, (int) config('integrations.inbound_webhooks.tries', 5));
        $this->backoff = array_map('intval', (array) config('integrations.inbound_webhooks.backoff', [30, 120, 600, 1800]));
    }

    public function process(TenantContext $context): void
    {
        $receipt = InboundWebhookReceipt::query()->find($this->receiptId);

        if (! $receipt instanceof InboundWebhookReceipt) {
            return;
        }

        if ($receipt->tenant_id !== null && $context->enabled()) {
            $context->run(Tenant::query()->findOrFail($receipt->tenant_id), fn () => $this->handleReceipt($receipt));

            return;
        }

        $this->handleReceipt($receipt);
    }

    private function handleReceipt(InboundWebhookReceipt $receipt): void
    {
        if (! $receipt->claimAttempt()) {
            return; // ya procesado por otro worker
        }

        $handlerClass = config("integrations.inbound_webhooks.providers.{$receipt->provider}.handler");

        try {
            $handler = is_string($handlerClass) ? app($handlerClass) : null;

            if (! $handler instanceof InboundWebhookHandlerInterface) {
                throw new PermanentIntegrationError("Sin handler válido para el proveedor {$receipt->provider}", $receipt->provider);
            }

            $handler->handle($receipt);
            $receipt->markProcessed();
        } catch (PermanentIntegrationError $e) {
            $receipt->markFailed(class_basename($e).': '.$e->getMessage());
        } catch (Throwable $e) {
            $receipt->markFailed(class_basename($e).': '.$e->getMessage());

            throw $e;
        }
    }
}
