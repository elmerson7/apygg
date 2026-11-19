<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Actor
 *
 * Quién hace la petición, para logs, auditoría y `deleted_by`. Mira primero el guard de la
 * API (`api`, JWT) y después el del back-office (`web`, sesión): el guard por defecto
 * (`auth.defaults.guard`) puede ser cualquiera de los dos, así que NUNCA se usa
 * el helper `auth()` o `Auth::id()` sin guard (lo prohíbe `tests/Arch/ArchTest.php`).
 *
 * Para decidir permisos usa el guard concreto de la ruta (`auth('api')`, `$request->user()`),
 * no este helper: aquí solo se identifica para dejar rastro.
 */
final class Actor
{
    public static function user(): ?Authenticatable
    {
        foreach (['api', 'web'] as $guard) {
            try {
                $user = Auth::guard($guard)->user();
            } catch (\Throwable) {
                // Un guard sin contexto (consola, sin token ni sesión) no es un error de auditoría.
                continue;
            }

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    public static function model(): ?User
    {
        $user = self::user();

        return $user instanceof User ? $user : null;
    }

    public static function id(): int|string|null
    {
        $id = self::user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
