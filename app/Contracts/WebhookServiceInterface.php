<?php

namespace App\Contracts;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Collection;

/**
 * WebhookServiceInterface
 *
 * Contrato para el servicio de gestión de webhooks.
 */
interface WebhookServiceInterface
{
    /**
     * Enviar webhook a una URL específica
     *
     * @param  Webhook  $webhook  Webhook a enviar
     * @param  string  $eventType  Tipo de evento (ej: 'user.created')
     * @param  array<string, mixed>  $payload  Datos del evento
     */
    public function send(Webhook $webhook, string $eventType, array $payload): WebhookDelivery;

    /**
     * Procesar entrega de webhook (llamado desde el Job)
     *
     * @param  Webhook  $webhook  Webhook a procesar
     * @param  WebhookDelivery  $delivery  Entrega a procesar
     * @return bool True si se entregó exitosamente
     */
    public function processDelivery(Webhook $webhook, WebhookDelivery $delivery): bool;

    /**
     * Firma HMAC de un webhook: `sha256=` + HMAC(secreto, "{timestamp}.{cuerpo}")
     *
     * @param  int  $timestamp  Valor de la cabecera X-Webhook-Timestamp
     * @param  string  $body  Cuerpo crudo tal como se envía
     * @param  string  $secret  Secreto del webhook
     */
    public function sign(int $timestamp, string $body, string $secret): string;

    /**
     * Verificar firma y timestamp de un webhook recibido
     *
     * @param  string  $body  Cuerpo CRUDO recibido
     * @param  string  $signatureHeader  Valor de X-Webhook-Signature (una o varias firmas)
     * @param  int  $timestamp  Valor de X-Webhook-Timestamp
     * @param  string|null  $oldSecret  Secreto anterior (rotación)
     */
    public function verify(string $body, string $signatureHeader, int $timestamp, string $secret, ?string $oldSecret = null, ?int $tolerance = null): bool;

    /**
     * Obtener webhooks activos DEL DUEÑO indicado que escuchan un evento específico
     *
     * @param  string  $eventType  Tipo de evento a filtrar
     * @param  int|string  $ownerId  Dueño del recurso del evento
     * @return Collection<int, Webhook> Colección de webhooks
     */
    public function getWebhooksForEvent(string $eventType, int|string $ownerId): Collection;

    /**
     * Reintentar entrega fallida
     *
     * @param  WebhookDelivery  $delivery  Entrega a reintentar
     * @return bool True si se despachó el reintento
     */
    public function retryDelivery(WebhookDelivery $delivery): bool;
}
