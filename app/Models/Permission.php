<?php

namespace App\Models;

use App\Traits\HasIlikeSearch;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permission Model
 *
 * Permiso del RBAC (nombre `recurso.accion`). Extiende el de
 * spatie/laravel-permission y conserva las columnas propias (display_name,
 * resource, action, description), el borrado lógico y los scopes por recurso/acción.
 *
 * @property string|null $display_name
 * @property string|null $resource
 * @property string|null $action
 * @property string|null $description
 */
class Permission extends SpatiePermission
{
    /** @use HasFactory<PermissionFactory> */
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
        return ['name', 'display_name', 'resource', 'action', 'description'];
    }

    /**
     * Scope para filtrar permisos por recurso.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForResource($query, string $resource)
    {
        return $query->where('resource', $resource);
    }

    /**
     * Scope para filtrar permisos por acción.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope para filtrar permisos por recurso y acción.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForResourceAndAction($query, string $resource, string $action)
    {
        return $query->where('resource', $resource)
            ->where('action', $action);
    }
}
