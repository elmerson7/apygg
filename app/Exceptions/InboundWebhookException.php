<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Rechazo de un webhook entrante. El `code` es estable y legible por máquina
 * (docs/api-response-structure.md): el proveedor y quien lo opera ramifican por él.
 */
class InboundWebhookException extends ApiException
{
    private function __construct(string $message, int $status, private readonly ErrorCode|string $webhookCode, array $errors = [])
    {
        parent::__construct($message, $status, $errors);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->webhookCode;
    }

    public static function unknownProvider(): self
    {
        return new self('Proveedor de webhooks desconocido', 404, 'webhook_provider_unknown');
    }

    public static function notConfigured(): self
    {
        return new self('Webhook no configurado', 500, 'webhook_not_configured');
    }

    public static function signatureMissing(): self
    {
        return new self('Falta la firma del webhook', 401, 'webhook_signature_missing');
    }

    public static function signatureInvalid(): self
    {
        return new self('Firma inválida', 401, 'webhook_signature_invalid');
    }

    public static function timestampExpired(): self
    {
        return new self('Timestamp fuera de la ventana permitida', 401, 'webhook_timestamp_expired');
    }

    public static function tooLarge(): self
    {
        return new self('El cuerpo del webhook supera el tamaño máximo', 413, ErrorCode::PayloadTooLarge);
    }

    public static function malformed(string $message = 'El cuerpo del webhook no es un JSON válido'): self
    {
        return new self($message, 400, 'webhook_payload_malformed');
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function invalidPayload(array $errors): self
    {
        return new self('Payload inválido', 422, ErrorCode::ValidationFailed, $errors);
    }

    public static function invalidTenant(): self
    {
        return new self('Tenant del webhook no válido', 422, ErrorCode::TenantInvalid);
    }
}
