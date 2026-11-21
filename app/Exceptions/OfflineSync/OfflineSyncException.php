<?php

namespace App\Exceptions\OfflineSync;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Errores del módulo `offline-sync` (docs/offline-sync.md). Cada uno lleva su `code` estable.
 */
class OfflineSyncException extends ApiException
{
    private function __construct(string $message, int $status, private readonly string $errorCodeString, array $extensions = [])
    {
        parent::__construct($message, $status, [], $errorCodeString, null, $extensions);
    }

    /** El ID del cliente ya existe (en este tenant o en otro: la respuesta es idéntica, no revela cuál). */
    public static function clientIdConflict(): self
    {
        return new self('El identificador no está disponible. Genera otro.', 409, 'client_id_conflict');
    }

    public static function invalidClientId(): self
    {
        return new self('El identificador no es un UUID válido para este recurso.', 422, 'invalid_client_id');
    }

    public static function clientIdNotSupported(): self
    {
        return new self('Este recurso no admite identificadores generados por el cliente.', 422, 'client_id_not_supported');
    }

    public static function preconditionFailed(string $currentEtag): self
    {
        return new self('El recurso cambió desde que lo leíste (If-Match no coincide).', 412, 'precondition_failed', ['current_etag' => $currentEtag]);
    }

    public static function preconditionRequired(): self
    {
        return new self('Falta If-Match: este recurso exige la versión que estás modificando.', 428, 'precondition_required');
    }

    public static function unknownResource(): self
    {
        return new self('Recurso no sincronizable.', 404, 'sync_resource_unknown');
    }

    public static function misconfigured(string $why): self
    {
        return new self($why, 500, 'sync_resource_misconfigured');
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->errorCodeString;
    }
}
