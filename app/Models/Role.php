<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role Model
 *
 * Modelo para roles del sistema RBAC.
 * Un rol puede tener múltiples permisos y puede ser asignado a múltiples usuarios.
 */
class Role extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    /**
     * Columnas buscables por defecto para el scope search()
     *
     * @return array<string>
     */
    protected function getSearchableColumns(): array
    {
        return ['name', 'display_name', 'description'];
    }

    /**
     * Relación muchos-a-muchos con Permission
     *
     * Un rol puede tener múltiples permisos.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permission',
            'role_id',
            'permission_id'
        )->withTimestamps();
    }

    /**
     * Relación muchos-a-muchos con User
     *
     * Un rol puede ser asignado a múltiples usuarios.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_role',
            'role_id',
            'user_id'
        )->withTimestamps();
    }

    /**
     * Verificar si el rol tiene un permiso específico
     *
     * @param  string  $permissionName  Nombre del permiso a verificar
     */
    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()
            ->where('name', $permissionName)
            ->exists();
    }

    /**
     * Asignar un permiso al rol
     *
     * @param  string|Permission  $permission  Permiso o nombre del permiso
     */
    public function assignPermission($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }

        if (! $this->hasPermission($permission->name)) {
            $this->permissions()->attach($permission->id);
        }
    }

    /**
     * Remover un permiso del rol
     *
     * @param  string|Permission  $permission  Permiso o nombre del permiso
     */
    public function removePermission($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }

        $this->permissions()->detach($permission->id);
    }
}
