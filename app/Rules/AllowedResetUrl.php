<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AllowedResetUrl
 *
 * La URL del enlace de recuperación solo se acepta si su origen
 * (esquema://host[:puerto]) está en `auth.password_reset_urls` o, si esa lista
 * está vacía, en `app.allowed_origins`. Evita que un atacante haga que la app
 * envíe a la víctima un correo legítimo que apunta a su dominio.
 */
class AllowedResetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! in_array(self::originOf($value), self::allowedOrigins(), true)) {
            $fail('La URL de reset no está permitida.');
        }
    }

    /**
     * @return array<int, string>
     */
    public static function allowedOrigins(): array
    {
        $origins = config('auth.password_reset_urls') ?: config('app.allowed_origins', []);

        /** @var array<array-key, mixed> $origins */
        return collect($origins)
            ->filter(fn ($origin) => is_string($origin) && $origin !== '' && ! str_contains($origin, '*'))
            ->map(fn (string $origin) => strtolower(rtrim(trim($origin), '/')))
            ->values()
            ->all();
    }

    /**
     * Origen normalizado de una URL, o null si no es http(s) válida o lleva
     * credenciales (`https://bueno.com@malo.com`).
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
