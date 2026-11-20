<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Errores de dominio de la impersonación. `code` estable: `impersonation_disabled`, `impersonation_forbidden`,
 * `impersonation_target_forbidden`, `impersonation_tenant_mismatch`, `not_impersonating`.
 */
class ImpersonationException extends ApiException
{
    public function __construct(string $message, int $status, private readonly string $stableCode)
    {
        parent::__construct($message, $status, [], $stableCode);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function disabled(): self
    {
        return new self('La impersonación está desactivada.', 403, 'impersonation_disabled');
    }

    public static function routeBlocked(): self
    {
        return new self('Esta operación no se puede hacer mientras se impersona a un usuario.', 403, 'impersonation_forbidden');
    }

    public static function targetForbidden(string $why): self
    {
        return new self($why, 403, 'impersonation_target_forbidden');
    }

    public static function tenantMismatch(): self
    {
        return new self('Esta sesión de impersonación está limitada a otra empresa.', 403, 'impersonation_tenant_mismatch');
    }

    public static function notImpersonating(): self
    {
        return new self('La sesión actual no es una impersonación.', 409, 'not_impersonating');
    }
}
