<?php

namespace App\Support\Tenancy\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Intento de leer, crear, mover o borrar un registro de otro tenant.
 */
class CrossTenantViolation extends ApiException
{
    public function __construct(string $message = 'El recurso pertenece a otro tenant.')
    {
        parent::__construct($message, 403, [], 'cross_tenant_violation');
    }

    public static function foreignRecord(string $model): self
    {
        return new self("{$model} pertenece a otro tenant.");
    }

    public static function tenantChanged(string $model): self
    {
        return new self("El tenant de un {$model} existente no se puede cambiar.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CrossTenant;
    }
}
