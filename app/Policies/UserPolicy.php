<?php

namespace App\Policies;

use App\Models\User;
use App\Services\LogService;

/**
 * UserPolicy
 *
 * Policy para autorización de acciones sobre usuarios.
 * Verifica permisos usando el sistema RBAC.
 *
 * @package App\Policies
 */
class UserPolicy
{
    /**
     * Determinar si el usuario puede ver cualquier usuario.
     *
     * @param User $user Usuario autenticado
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.read');
    }

    /**
     * Determinar si el usuario puede ver el usuario específico.
     *
     * @param User $user Usuario autenticado
     * @param User $model Usuario a verificar
     * @return bool
     */
    public function view(User $user, User $model): bool
    {
        // Puede ver su propio perfil o si tiene permiso users.read
        return $user->id === $model->id || $user->hasPermission('users.read');
    }

    /**
     * Determinar si el usuario puede crear usuarios.
     *
     * @param User $user Usuario autenticado
     * @return bool
     */
    public function create(User $user): bool
    {
        $allowed = $user->hasPermission('users.create');

        if ($allowed) {
            LogService::info('Intento de crear usuario autorizado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede actualizar el usuario específico.
     *
     * @param User $user Usuario autenticado
     * @param User $model Usuario a actualizar
     * @return bool
     */
    public function update(User $user, User $model): bool
    {
        // Puede actualizar su propio perfil o si tiene permiso users.update
        $allowed = $user->id === $model->id || $user->hasPermission('users.update');

        if ($allowed && $user->id !== $model->id) {
            LogService::info('Intento de actualizar usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede eliminar el usuario específico.
     *
     * @param User $user Usuario autenticado
     * @param User $model Usuario a eliminar
     * @return bool
     */
    public function delete(User $user, User $model): bool
    {
        // No puede eliminarse a sí mismo
        if ($user->id === $model->id) {
            return false;
        }

        $allowed = $user->hasPermission('users.delete');

        if ($allowed) {
            LogService::info('Intento de eliminar usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede restaurar el usuario específico.
     *
     * @param User $user Usuario autenticado
     * @param User $model Usuario a restaurar
     * @return bool
     */
    public function restore(User $user, User $model): bool
    {
        $allowed = $user->hasPermission('users.restore');

        if ($allowed) {
            LogService::info('Intento de restaurar usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede eliminar permanentemente el usuario específico.
     *
     * @param User $user Usuario autenticado
     * @param User $model Usuario a eliminar permanentemente
     * @return bool
     */
    public function forceDelete(User $user, User $model): bool
    {
        // Solo administradores pueden eliminar permanentemente
        $allowed = $user->isAdmin() && $user->hasPermission('users.forceDelete');

        if ($allowed) {
            LogService::warning('Intento de eliminación permanente de usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }
}
