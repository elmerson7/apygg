<?php

namespace App\Contracts;

use App\Exceptions\AuditException;
use App\Exceptions\BusinessLogicException;
use App\Models\Tenant;
use App\Models\User;

/**
 * Administración de empresas por la plataforma (back-office): suspender y reactivar un tenant.
 * Distinto de `TenantMemberServiceInterface`, que gestiona a los miembros desde dentro del tenant.
 */
interface TenantAdminServiceInterface
{
    /**
     * Suspende un tenant activo (el middleware `tenant` pasa a responder 403 `tenant_suspended`).
     * El motivo es obligatorio (`tenant.suspend` en `audit.reason_required`) y queda auditado.
     *
     * @throws AuditException `audit_reason_required`
     * @throws BusinessLogicException si no está activo
     */
    public function suspend(Tenant $tenant, ?string $reason, ?User $actor = null): Tenant;

    /**
     * Reactiva un tenant suspendido. Motivo obligatorio y auditado.
     *
     * @throws AuditException `audit_reason_required`
     * @throws BusinessLogicException si no está suspendido
     */
    public function reactivate(Tenant $tenant, ?string $reason, ?User $actor = null): Tenant;
}
