<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\TenantAdminServiceInterface;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessLogicException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspender y reactivar empresas desde la plataforma. La fila se bloquea para que dos operadores
 * simultáneos no pisen el estado, y el motivo se valida ANTES de tocar nada.
 */
class TenantAdminService implements TenantAdminServiceInterface
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function suspend(Tenant $tenant, ?string $reason, ?User $actor = null): Tenant
    {
        return $this->transition($tenant, 'tenant.suspend', $reason, TenantStatus::Active, TenantStatus::Suspended, 'Solo se puede suspender una empresa activa.');
    }

    public function reactivate(Tenant $tenant, ?string $reason, ?User $actor = null): Tenant
    {
        return $this->transition($tenant, 'tenant.reactivate', $reason, TenantStatus::Suspended, TenantStatus::Active, 'Solo se puede reactivar una empresa suspendida.');
    }

    private function transition(Tenant $tenant, string $action, ?string $reason, TenantStatus $from, TenantStatus $to, string $invalid): Tenant
    {
        $reason = $this->audit->requireReason($action, $reason);

        return DB::transaction(function () use ($tenant, $action, $reason, $from, $to, $invalid): Tenant {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw new BusinessLogicException($invalid, 'tenant_invalid_status');
            }

            $locked->update(['status' => $to]);
            $this->audit->recordAction($action, $locked, $reason, ['from' => $from->value, 'to' => $to->value], (int) $locked->getKey());

            return $locked;
        });
    }
}
