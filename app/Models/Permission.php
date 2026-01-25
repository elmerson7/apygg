<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission Model
 *
 * Modelo para permisos del sistema RBAC.
 * Un permiso puede pertenecer a múltiples roles y puede ser asignado directamente a usuarios.
 */
class Permission extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'resource',
        'action',
        'description',
    ];

    /**
     * Columnas buscables por defecto para el scope search()
     *
     * @return array<string>
     */
    protected function getSearchableColumns(): array
    {
        return ['name', 'display_name', 'resource', 'action', 'description'];
    }

    /**
     * Relación muchos-a-muchos con Role
     *
     * Un permiso puede pertenecer a múltiples roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permission',
            'permission_id',
            'role_id'
        )->withTimestamps();
    }

    /**
     * Relación muchos-a-muchos con User (permisos directos)
     *
     * Un permiso puede ser asignado directamente a múltiples usuarios.
     * Estos permisos sobrescriben los permisos heredados de roles.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_permission',
            'permission_id',
            'user_id'
        )->withTimestamps();
    }

    /**
     * Scope para filtrar permisos por recurso
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $resource  Nombre del recurso
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForResource($query, string $resource)
    {
        return $query->where('resource', $resource);
    }

    /**
     * Scope para filtrar permisos por acción
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $action  Nombre de la acción
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope para filtrar permisos por recurso y acción
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForResourceAndAction($query, string $resource, string $action)
    {
        return $query->where('resource', $resource)
            ->where('action', $action);
    }
}
