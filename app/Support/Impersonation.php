<?php

namespace App\Support;

use App\Auth\ApiJwtGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Lee los claims de impersonación del JWT de la petición en curso (docs/impersonation.md).
 *
 * Sin estado propio (Octane): se consulta el payload que el guard `api` ya parseó. Solo es fiable DESPUÉS de
 * que el guard haya autenticado la petición (el middleware `EnforceImpersonation` lo fuerza al principio).
 */
final class Impersonation
{
    public const CLAIM = 'imp';

    public const TENANT_CLAIM = 'imp_tenant';

    /** Id del usuario de soporte que está suplantando, o null si la petición no es una impersonación. */
    public static function actorId(): ?string
    {
        $value = self::claim(self::CLAIM);

        return is_int($value) || (is_string($value) && $value !== '') ? (string) $value : null;
    }

    /** Tenant al que se acotó la impersonación (null = sin acotar). */
    public static function tenantId(): ?int
    {
        $value = self::claim(self::TENANT_CLAIM);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /** `sid` de la sesión creada para esta impersonación. */
    public static function sessionId(): ?string
    {
        $value = self::claim('sid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function claim(string $name): mixed
    {
        try {
            $guard = Auth::guard('api');

            if (! $guard instanceof ApiJwtGuard || ! $guard->hasUser()) {
                return null;
            }

            return $guard->getPayload()->get($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
