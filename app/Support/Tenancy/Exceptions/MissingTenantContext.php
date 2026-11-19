<?php

namespace App\Support\Tenancy\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Se necesita un tenant y no hay ninguno en el contexto (fail-closed).
 *
 * Como respuesta HTTP es un 400 (la ruta exige indicar el tenant); lanzada desde un modelo,
 * un job o un comando es un error de programación: falta `TenantContext::run()`.
 */
class MissingTenantContext extends ApiException
{
    public function __construct(string $message = 'Esta operación requiere indicar el tenant activo.')
    {
        parent::__construct($message, 400, [], 'tenant_required');
    }

    public static function forModel(string $model, string $operation): self
    {
        return new self("Sin tenant activo no se puede {$operation} {$model}: usa TenantContext::run() o TenantQuery::withoutTenant().");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantRequired;
    }
}
