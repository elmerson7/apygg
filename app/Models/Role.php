<?php

namespace App\Models;

use App\Support\Tenancy\PermissionTeams;
use App\Support\Tenancy\PermissionTeamsSchema;
use App\Support\Tenancy\TenantContext;
use App\Traits\HasIlikeSearch;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Con equipos de Spatie (multiempresa) `roles.tenant_id` NULL es un rol GLOBAL (catálogo común) y con
     * valor es propio de ese tenant. Sin tenant activo (plataforma) un rol nuevo es global: Spatie le
     * pondría el equipo 0, que no es un tenant. Ver docs/permissions.md §Teams.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = [])
    {
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');

        if (PermissionTeams::enabled()
            && ! array_key_exists($teamKey, $attributes)
            && (int) getPermissionsTeamId() === PermissionTeamsSchema::PLATFORM_TEAM) {
            $attributes[$teamKey] = null;
        }

        return parent::create($attributes);
    }

    /**
     * Roles visibles desde el ámbito actual: en un tenant, los globales y los suyos; en la
     * plataforma, todos. No es un scope global A PROPÓSITO: la caché de permisos de Spatie carga
     * `Permission::with('roles')` y un scope dependiente del tenant la corrompería para los demás.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeVisibleInScope(Builder $query): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        if (! PermissionTeams::enabled() || $tenantId === null) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn((string) config('permission.column_names.team_foreign_key', 'tenant_id'));

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $tenantId));
    }

    /**
     * ¿Este rol NO se puede modificar desde el ámbito actual? Un tenant no toca los roles globales
     * (cambiarían para todos los tenants) ni los de otro tenant; la plataforma sí.
     */
    public function isOutOfScope(): bool
    {
        $tenantId = app(TenantContext::class)->id();

        if (! PermissionTeams::enabled() || $tenantId === null) {
            return false;
        }

        $owner = $this->getAttribute((string) config('permission.column_names.team_foreign_key', 'tenant_id'));

        return $owner === null || (int) $owner !== $tenantId;
    }

    protected function getSearchableColumns(): array
    {
        return ['name', 'display_name', 'description'];
    }
}
