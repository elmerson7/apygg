<?php

namespace App\Contracts;

use App\Models\InboundWebhookReceipt;

/**
 * Procesa un webhook entrante YA verificado y guardado. Corre en cola, dentro del tenant del recibo
 * (si lo tiene). Debe ser idempotente: el job puede repetirse. Una excepción reintenta; una
 * `PermanentIntegrationError` marca el recibo como `failed` sin reintentar.
 */
interface InboundWebhookHandlerInterface
{
    public function handle(InboundWebhookReceipt $receipt): void;
}
