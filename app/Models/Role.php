<?php

namespace App\Models;

use App\Traits\HasIlikeSearch;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role Model
 *
 * Rol del RBAC. Extiende el de spatie/laravel-permission (permisos, asignación a
 * usuarios, caché), conservando las columnas propias (display_name, description),
 * el borrado lógico y el scope search().
 *
 * Los permisos del rol se gestionan con la API de Spatie:
 * givePermissionTo(), revokePermissionTo(), syncPermissions(), hasPermissionTo().
 *
 * @property string|null $display_name
 * @property string|null $description
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasIlikeSearch, SoftDeletes;

    /**
     * Guard de la API (JWT): todos los roles y permisos viven en él.
     *
     * @var string
     */
    protected $guard_name = 'api';

    /** @var list<string> */
    protected $hidden = ['pivot'];

    /**
     * Columnas buscables por defecto para el scope search().
     *
     * @return array<string>
     */
    protected function getSearchableColumns(): array
    {
        return ['name', 'display_name', 'description'];
    }
}
