<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\LogService;

/**
 * RolePolicy
 *
 * Policy para autorización de acciones sobre roles.
 * Verifica permisos usando el sistema RBAC.
 */
class RolePolicy
{
    /**
     * Determinar si el usuario puede ver cualquier rol.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function viewAny(User $user): bool
    {
        return $user->can('roles.read');
    }

    /**
     * Determinar si el usuario puede ver el rol específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol a verificar
     */
    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.read');
    }

    /**
     * Determinar si el usuario puede crear roles.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function create(User $user): bool
    {
        $allowed = $user->can('roles.create');

        if ($allowed) {
            LogService::info('Intento de crear rol autorizado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede actualizar el rol específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol a actualizar
     */
    public function update(User $user, Role $role): bool
    {
        if ($role->isOutOfScope()) {
            return false;
        }

        // No se puede modificar el rol 'admin' a menos que sea admin
        if ($role->name === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $allowed = $user->can('roles.update');

        if ($allowed) {
            LogService::info('Intento de actualizar rol autorizado', [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede eliminar el rol específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol a eliminar
     */
    public function delete(User $user, Role $role): bool
    {
        if ($role->isOutOfScope()) {
            return false;
        }

        // No se puede eliminar el rol 'admin'
        if ($role->name === 'admin') {
            return false;
        }

        $allowed = $user->can('roles.delete');

        if ($allowed) {
            LogService::info('Intento de eliminar rol autorizado', [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede asignar permisos a un rol.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol al que se asignará el permiso
     */
    public function assignPermission(User $user, Role $role): bool
    {
        // Multiempresa: un tenant no cambia los permisos de un rol global ni de otro tenant
        if ($role->isOutOfScope()) {
            return false;
        }

        if ($role->name === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $allowed = $user->can('roles.manage-permissions');

        if ($allowed) {
            LogService::info('Intento de asignar permiso a rol autorizado', [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ], 'security');
        }

        return $allowed;
    }

    public function removePermission(User $user, Role $role): bool
    {
        return $this->assignPermission($user, $role);
    }

    /**
     * Determinar si el usuario puede dar (o quitar) este rol a otro usuario.
     *
     * Nadie asigna un rol con más privilegios que los suyos: el rol admin solo
     * lo asigna un admin y los demás roles solo si todos sus permisos los tiene
     * también quien asigna.
     */
    public function assign(User $user, Role $role): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($role->name === 'admin') {
            return false;
        }

        foreach ($role->permissions()->pluck('name') as $permissionName) {
            if (! $user->checkPermissionTo($permissionName)) {
                return false;
            }
        }

        return true;
    }
}
