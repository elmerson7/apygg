<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Prefijo de versión opcional de la API (`config('api.prefix')`, p. ej. `v1`; vacío por defecto).
 *
 * Sin estado: lee la config en cada llamada (seguro con Octane). Los middlewares y servicios que
 * comparan la ruta con patrones (`health`, `auth/*`, `webhooks/inbound/*`...) usan `path()`/`is()`
 * para que esos patrones valgan igual con y sin prefijo. Ver docs/api-routes-conventions.md.
 */
final class ApiPath
{
    /** `v1`, `/v1`, `v1/` y `/v1/` -> `v1`; vacío -> ''. */
    public static function normalize(mixed $raw): string
    {
        return trim(trim((string) $raw), '/');
    }

    public static function prefix(): string
    {
        return self::normalize(config('api.prefix', ''));
    }

    /** Quita el prefijo (si lo hay) de una ruta; sin prefijo configurado la devuelve intacta. */
    public static function strip(string $path): string
    {
        $prefix = self::prefix();

        if ($prefix === '') {
            return $path;
        }

        $clean = ltrim($path, '/');
        $lead = strlen($path) - strlen($clean) > 0 ? '/' : '';

        if ($clean === $prefix) {
            return $lead === '' ? '' : '/';
        }

        if (str_starts_with($clean, $prefix.'/')) {
            return $lead.substr($clean, strlen($prefix) + 1);
        }

        return $path;
    }

    /** Antepone el prefijo a una ruta relativa (`/users` -> `/v1/users`); sin prefijo la devuelve intacta. */
    public static function with(string $path): string
    {
        $prefix = self::prefix();

        if ($prefix === '') {
            return $path;
        }

        $clean = trim($path, '/');

        return '/'.$prefix.($clean === '' ? '' : '/'.$clean);
    }

    /** `Request::path()` sin el prefijo de versión. */
    public static function path(Request $request): string
    {
        return self::strip($request->path());
    }

    /** Como `Request::is()`, pero sobre la ruta sin el prefijo de versión. */
    public static function is(Request $request, string ...$patterns): bool
    {
        $path = trim(self::strip($request->decodedPath()), '/');
        $path = $path === '' ? '/' : $path;

        foreach ($patterns as $pattern) {
            $pattern = $pattern === '/' ? '/' : trim($pattern, '/');

            if ($pattern === '*' || Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** ¿La ruta (relativa, con o sin `/` inicial) es una de las que nunca llevan prefijo? */
    public static function isUnprefixed(string $path): bool
    {
        $clean = trim($path, '/');
        $clean = $clean === '' ? '/' : $clean;

        foreach ((array) config('api.unprefixed', []) as $pattern) {
            $pattern = $pattern === '/' ? '/' : trim((string) $pattern, '/');

            if (Str::is($pattern, $clean)) {
                return true;
            }
        }

        return false;
    }
}
