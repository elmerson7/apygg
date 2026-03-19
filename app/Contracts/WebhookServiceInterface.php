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
     * @param  array  $payload  Datos del evento
     * @return WebhookDelivery
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
     * Validar firma HMAC de un webhook recibido
     *
     * @param  array  $payload  Payload recibido
     * @param  string  $signature  Firma recibida en el header
     * @param  string  $secret  Secret para validar
     * @return bool True si la firma es válida
     */
    public function validateSignature(array $payload, string $signature, string $secret): bool;

    /**
     * Validar timestamp de un webhook recibido
     *
     * @param  int  $timestamp  Timestamp recibido en el header
     * @param  int|null  $tolerance  Tolerancia en segundos (default: config)
     * @return bool True si el timestamp es válido
     */
    public function validateTimestamp(int $timestamp, ?int $tolerance = null): bool;

    /**
     * Validar webhook completo (firma + timestamp)
     *
     * @param  array  $payload  Payload recibido
     * @param  string  $signature  Firma recibida en el header
     * @param  int  $timestamp  Timestamp recibido en el header
     * @param  string  $secret  Secret para validar
     * @param  string|null  $oldSecret  Secret anterior (opcional, para rotación)
     * @return bool True si el webhook es válido
     */
    public function validateWebhook(
        array $payload,
        string $signature,
        int $timestamp,
        string $secret,
        ?string $oldSecret = null
    ): bool;

    /**
     * Obtener webhooks activos que escuchan un evento específico
     *
     * @param  string  $eventType  Tipo de evento a filtrar
     * @return Collection<int, Webhook> Colección de webhooks
     */
    public function getWebhooksForEvent(string $eventType): Collection;

    /**
     * Reintentar entrega fallida
     *
     * @param  WebhookDelivery  $delivery  Entrega a reintentar
     * @return bool True si se despachó el reintento
     */
    public function retryDelivery(WebhookDelivery $delivery): bool;

    /**
     * Preparar payload con metadatos adicionales
     *
     * @param  array  $payload  Payload original
     * @param  Webhook  $webhook  Webhook asociado
     * @param  WebhookDelivery  $delivery  Entrega asociada
     * @return array Payload con metadatos
     */
    public function preparePayload(array $payload, Webhook $webhook, WebhookDelivery $delivery): array;

    /**
     * Generar firma HMAC-SHA256 del payload
     *
     * @param  array  $payload  Payload a firmar
     * @param  string  $secret  Secret para la firma
     * @return string Firma HMAC
     */
    public function generateSignature(array $payload, string $secret): string;

    /**
     * Preparar headers HTTP para el webhook
     *
     * @param  string  $signature  Firma para incluir en headers
     * @param  Webhook  $webhook  Webhook para obtener configuración
     * @return array Headers HTTP
     */
    public function prepareHeaders(string $signature, Webhook $webhook): array;

    /**
     * Calcular delay para reintento usando backoff exponencial
     *
     * @param  int  $attempts  Número de intento actual
     * @return int Segundos de delay
     */
    public function calculateRetryDelay(int $attempts): int;
}