<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use App\Services\LogService;

/**
 * PermissionPolicy
 *
 * Policy para autorización de acciones sobre permisos.
 * Verifica permisos usando el sistema RBAC.
 *
 * @package App\Policies
 */
class PermissionPolicy
{
    /**
     * Determinar si el usuario puede ver cualquier permiso.
     *
     * @param User $user Usuario autenticado
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('permissions.read');
    }

    /**
     * Determinar si el usuario puede ver el permiso específico.
     *
     * @param User $user Usuario autenticado
     * @param Permission $permission Permiso a verificar
     * @return bool
     */
    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermission('permissions.read');
    }

    /**
     * Determinar si el usuario puede crear permisos.
     *
     * @param User $user Usuario autenticado
     * @return bool
     */
    public function create(User $user): bool
    {
        // Solo administradores pueden crear permisos
        $allowed = $user->isAdmin() && $user->hasPermission('permissions.create');

        if ($allowed) {
            LogService::info('Intento de crear permiso autorizado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede actualizar el permiso específico.
     *
     * @param User $user Usuario autenticado
     * @param Permission $permission Permiso a actualizar
     * @return bool
     */
    public function update(User $user, Permission $permission): bool
    {
        // Solo administradores pueden actualizar permisos
        $allowed = $user->isAdmin() && $user->hasPermission('permissions.update');

        if ($allowed) {
            LogService::info('Intento de actualizar permiso autorizado', [
                'user_id' => $user->id,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede eliminar el permiso específico.
     *
     * @param User $user Usuario autenticado
     * @param Permission $permission Permiso a eliminar
     * @return bool
     */
    public function delete(User $user, Permission $permission): bool
    {
        // Solo administradores pueden eliminar permisos
        $allowed = $user->isAdmin() && $user->hasPermission('permissions.delete');

        if ($allowed) {
            LogService::warning('Intento de eliminar permiso autorizado', [
                'user_id' => $user->id,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
            ], 'security');
        }

        return $allowed;
    }
}
