<?php

namespace App\Helpers;

/**
 * DevToolsAccess
 *
 * Decide quién entra a Telescope y Horizon. Solo APP_ENV=local queda abierto;
 * en cualquier otro entorno hace falta un usuario admin o con el email en
 * config('app.devtools_allowed_emails').
 */
class DevToolsAccess
{
    public static function allows(mixed $user): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        return in_array($user->email ?? null, (array) config('app.devtools_allowed_emails', []), true);
    }
}
