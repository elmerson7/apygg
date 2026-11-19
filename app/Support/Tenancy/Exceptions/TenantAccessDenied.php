<?php

namespace App\Support\Tenancy\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * El tenant no existe o el usuario no es miembro activo. Es la MISMA respuesta en ambos casos
 * para no revelar qué tenants existen.
 */
class TenantAccessDenied extends ApiException
{
    public function __construct(string $message = 'No tienes acceso a este tenant.')
    {
        parent::__construct($message, 403, [], 'tenant_forbidden');
    }

    public static function unauthenticated(): self
    {
        return new self('Hay que autenticarse para usar un tenant.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantInvalid;
    }
}
