<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Miembros del tenant activo: SIEMPRE filtra por `tenant_user.tenant_id` (nunca ve otra empresa).
 */
class TenantMembersExporter extends Exporter
{
    public function key(): string
    {
        return 'tenant-members';
    }

    public function permission(): string
    {
        return 'tenant-members.export';
    }

    public function requiresTenant(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('id', 'export.members.id', 'int'),
            new ExportColumn('name', 'export.members.name'),
            new ExportColumn('email', 'export.members.email'),
            new ExportColumn('tenant_role', 'export.members.role'),
            new ExportColumn('tenant_status', 'export.members.status'),
            new ExportColumn('tenant_joined_at', 'export.members.joined_at', 'datetime'),
        ];
    }

    public function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,suspended,invited'],
            'role' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function idColumn(): string
    {
        return 'users.id';
    }

    public function query(array $filters, ?int $tenantId): Builder
    {
        $query = User::query()
            ->select('users.*', 'tenant_user.role as tenant_role', 'tenant_user.status as tenant_status', 'tenant_user.joined_at as tenant_joined_at')
            ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
            ->where('tenant_user.tenant_id', $tenantId ?? 0);

        if (! empty($filters['status'])) {
            $query->where('tenant_user.status', $filters['status']);
        }

        if (! empty($filters['role'])) {
            $query->where('tenant_user.role', $filters['role']);
        }

        return $query;
    }
}
