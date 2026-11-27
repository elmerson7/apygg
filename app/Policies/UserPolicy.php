<?php

namespace App\Policies;

use App\Enums\TenantMembershipStatus;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Support\Facades\DB;

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
        return $user->can('users.read');
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
        return $user->id === $model->id || $user->can('users.read');
    }

    /**
     * Determinar si el usuario puede crear usuarios.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function create(User $user): bool
    {
        $allowed = $user->can('users.create');

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
            $allowed = $user->can('users.update')
                && (! $model->isAdmin() || $user->isAdmin())
                && $this->outranksOrEquals($user, $model);
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
     * ¿Puede `$actor` administrar la cuenta de `$target`? Misma regla que RolePolicy::assign: nadie
     * toma el control (email, contraseña) de alguien con más privilegios que los suyos.
     *
     * - Un admin puede con cualquiera (el caso admin → admin ya lo filtra quien llama).
     * - Si no, cada permiso efectivo del objetivo (directo o por rol: dueño de empresa, soporte con
     *   `tenants.access-any`, `users.impersonate`...) debe tenerlo también el actor.
     * - Y el objetivo no puede ser miembro activo de una empresa en la que el actor no lo es.
     */
    private function outranksOrEquals(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        foreach ($target->getAllPermissions()->pluck('name') as $permission) {
            if (! $actor->checkPermissionTo($permission)) {
                return false;
            }
        }

        $active = TenantMembershipStatus::Active->value;
        $actorTenants = DB::table('tenant_user')->where('user_id', $actor->getKey())->where('status', $active)->pluck('tenant_id');

        return ! DB::table('tenant_user')
            ->where('user_id', $target->getKey())
            ->where('status', $active)
            ->whereNotIn('tenant_id', $actorTenants)
            ->exists();
    }

    /**
     * Determinar si el usuario puede asignar o quitar roles al usuario.
     *
     * Exige users.manage-roles; solo un admin gestiona los roles de un admin.
     */
    public function manageRoles(User $user, User $model): bool
    {
        return $user->can('users.manage-roles')
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
        $allowed = $user->can('users.delete')
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
        $allowed = $user->can('users.restore');

        if ($allowed) {
            LogService::info('Intento de restaurar usuario autorizado', [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
            ], 'security');
        }

        return $allowed;
    }
}
