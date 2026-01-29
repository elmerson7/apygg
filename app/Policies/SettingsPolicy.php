<?php

namespace App\Policies;

use App\Models\Settings;
use App\Models\User;
use App\Services\LogService;

/**
 * SettingsPolicy
 *
 * Policy para autorización de acciones sobre Settings.
 * Solo administradores pueden gestionar settings.
 */
class SettingsPolicy
{
    /**
     * Determinar si el usuario puede ver cualquier setting.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function viewAny(User $user): bool
    {
        $allowed = $user->isAdmin();

        if ($allowed) {
            LogService::info('Intento de ver settings autorizado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede ver el setting específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Settings  $settings  Setting a verificar
     */
    public function view(User $user, Settings $settings): bool
    {
        $allowed = $user->isAdmin();

        if ($allowed) {
            LogService::info('Intento de ver setting autorizado', [
                'user_id' => $user->id,
                'setting_id' => $settings->id,
                'setting_key' => $settings->key,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede crear settings.
     *
     * @param  User  $user  Usuario autenticado
     */
    public function create(User $user): bool
    {
        $allowed = $user->isAdmin();

        if ($allowed) {
            LogService::info('Intento de crear setting autorizado', [
                'user_id' => $user->id,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede actualizar el setting específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Settings  $settings  Setting a actualizar
     */
    public function update(User $user, Settings $settings): bool
    {
        $allowed = $user->isAdmin();

        if ($allowed) {
            LogService::info('Intento de actualizar setting autorizado', [
                'user_id' => $user->id,
                'setting_id' => $settings->id,
                'setting_key' => $settings->key,
            ], 'security');
        }

        return $allowed;
    }

    /**
     * Determinar si el usuario puede eliminar el setting específico.
     *
     * @param  User  $user  Usuario autenticado
     * @param  Settings  $settings  Setting a eliminar
     */
    public function delete(User $user, Settings $settings): bool
    {
        $allowed = $user->isAdmin();

        if ($allowed) {
            LogService::warning('Intento de eliminar setting autorizado', [
                'user_id' => $user->id,
                'setting_id' => $settings->id,
                'setting_key' => $settings->key,
            ], 'security');
        }

        return $allowed;
    }
}
