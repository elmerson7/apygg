<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Errores de dominio de la auditoría. `code` estable: `audit_reason_required`, `audit_immutable`.
 */
class AuditException extends ApiException
{
    public function __construct(string $message, int $status, private readonly string $stableCode)
    {
        parent::__construct($message, $status, [], $stableCode);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function reasonRequired(string $action): self
    {
        return new self("La acción «{$action}» exige indicar un motivo (reason).", 422, 'audit_reason_required');
    }

    public static function immutable(): self
    {
        return new self('Las entradas de auditoría no se pueden modificar ni borrar.', 500, 'audit_immutable');
    }
}
