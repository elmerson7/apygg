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
        return $user->hasPermission('roles.read');
    }

    /**
     * Determinar si el usuario puede ver el rol específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol a verificar
     */
    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.read');
    }

    /**
     * Determinar si el usuario puede crear roles.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function create(User $user): bool
    {
        $allowed = $user->hasPermission('roles.create');

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
        // No se puede modificar el rol 'admin' a menos que sea admin
        if ($role->name === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $allowed = $user->hasPermission('roles.update');

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
        // No se puede eliminar el rol 'admin'
        if ($role->name === 'admin') {
            return false;
        }

        $allowed = $user->hasPermission('roles.delete');

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
        // No se puede modificar permisos del rol 'admin' a menos que sea admin
        if ($role->name === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $allowed = $user->hasPermission('roles.assignPermission');

        if ($allowed) {
            LogService::info('Intento de asignar permiso a rol autorizado', [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede remover permisos de un rol.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Role  $role  Rol del que se removerá el permiso
     */
    public function removePermission(User $user, Role $role): bool
    {
        // No se puede modificar permisos del rol 'admin' a menos que sea admin
        if ($role->name === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $allowed = $user->hasPermission('roles.removePermission');

        if ($allowed) {
            LogService::info('Intento de remover permiso de rol autorizado', [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ], 'security');
        }

        return $allowed;
    }
}
