<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Usuarios de la plataforma (admin). Sin datos sensibles: nada de contraseñas, tokens ni secretos MFA.
 */
class UsersExporter extends Exporter
{
    public function key(): string
    {
        return 'users';
    }

    public function permission(): string
    {
        return 'users.export';
    }

    public function columns(): array
    {
        return [
            new ExportColumn('id', 'export.users.id', 'int'),
            new ExportColumn('name', 'export.users.name'),
            new ExportColumn('email', 'export.users.email'),
            new ExportColumn('username', 'export.users.username'),
            new ExportColumn('roles', 'export.users.roles', 'text', fn (User $u) => $u->roles->pluck('name')->implode(', ')),
            new ExportColumn('locale', 'export.users.locale'),
            new ExportColumn('email_verified_at', 'export.users.email_verified_at', 'datetime'),
            new ExportColumn('created_at', 'export.users.created_at', 'datetime'),
        ];
    }

    public function filterRules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function query(array $filters, ?int $tenantId): Builder
    {
        $query = User::query()->with('roles');

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.name', $filters['role']));
        }

        if (! empty($filters['search'])) {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $query->where(fn ($q) => $q->where('name', 'ILIKE', $like)->orWhere('email', 'ILIKE', $like));
        }

        return $query;
    }
}
