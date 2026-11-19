<?php

namespace App\Support\Tenancy\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * El tenant existe y el usuario tiene acceso, pero el tenant no está activo.
 */
class TenantSuspended extends ApiException
{
    public function __construct()
    {
        parent::__construct('El tenant está suspendido.', 403, [], 'tenant_suspended');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantInactive;
    }
}
