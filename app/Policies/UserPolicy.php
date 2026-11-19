<?php

namespace App\Policies;

use App\Models\User;
use App\Services\LogService;

/**
 * UserPolicy
 *
 * Policy para autorización de acciones sobre usuarios.
 * Verifica permisos usando el sistema RBAC.
 */
class UserPolicy
{
    /**
     * Determinar si el usuario puede ver cualquier usuario.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.read');
    }

    /**
     * Determinar si el usuario puede ver el usuario específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  User  $model  Usuario a verificar
     */
    public function view(User $user, User $model): bool
    {
        // Puede ver su propio perfil o si tiene permiso users.read
        return $user->id === $model->id || $user->hasPermission('users.read');
    }

    /**
     * Determinar si el usuario puede crear usuarios.
     *
     * @param  User  $user  Usuario autenticado
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
     * @param  User  $user  Usuario autenticado
     * @param  User  $model  Usuario a actualizar
     */
    public function update(User $user, User $model): bool
    {
        // Su propio perfil (el controller limita los campos) o users.update.
        // Solo un admin edita a otro admin: users.update no debe servir para
        // tomar la cuenta de un administrador.
        if ($user->id === $model->id) {
            $allowed = true;
        } else {
            $allowed = $user->hasPermission('users.update')
                && (! $model->isAdmin() || $user->isAdmin());
        }

        if ($allowed && $user->id !== $model->id) {
            LogService::info('Intento de actualizar usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede asignar o quitar roles al usuario.
     *
     * Exige users.manage-roles; solo un admin gestiona los roles de un admin.
     */
    public function manageRoles(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage-roles')
            && (! $model->isAdmin() || $user->isAdmin());
    }

    /**
     * Determinar si el usuario puede eliminar el usuario específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  User  $model  Usuario a eliminar
     */
    public function delete(User $user, User $model): bool
    {
        // No puede eliminarse a sí mismo
        if ($user->id === $model->id) {
            return false;
        }

        // Solo un admin elimina a otro admin
        $allowed = $user->hasPermission('users.delete')
            && (! $model->isAdmin() || $user->isAdmin());

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
     * @param  User  $user  Usuario autenticado
     * @param  User  $model  Usuario a restaurar
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
     * @param  User  $user  Usuario autenticado
     * @param  User  $model  Usuario a eliminar permanentemente
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
