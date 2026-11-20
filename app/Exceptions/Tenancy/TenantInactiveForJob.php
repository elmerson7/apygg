<?php

namespace App\Exceptions\Tenancy;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Un job de un tenant suspendido o pendiente no se ejecuta (docs/queues-and-jobs.md, «Tenants no activos»).
 * Falla en vez de correr: queda en `failed_jobs` y se puede reencolar al reactivar el tenant.
 */
class TenantInactiveForJob extends ApiException
{
    public function __construct(int $tenantId, string $status)
    {
        parent::__construct("El tenant {$tenantId} no está activo ({$status}): el job no se ejecuta.", 403, [], 'tenant_inactive');
    }

    public function errorCode(): ErrorCode|string
    {
        return ErrorCode::TenantInactive;
    }
}
