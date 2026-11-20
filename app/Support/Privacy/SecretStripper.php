<?php

namespace App\Support\Privacy;

/**
 * Última barrera de la exportación: quita de cualquier estructura las claves que nunca deben salir de la
 * plataforma (contraseñas, secretos 2FA, tokens, hashes de claves), aunque un contribuidor se despiste.
 */
final class SecretStripper
{
    private const EXACT = ['password', 'token', 'secret', 'key', 'api_key', 'remember_token', 'authorization', 'cookie'];

    private const FRAGMENTS = ['password', 'secret', 'recovery_code', 'remember_token', 'access_token', 'refresh_token', 'private_key', 'two_factor'];

    public static function strip(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                continue;
            }

            $out[$key] = self::strip($value);
        }

        return $out;
    }

    public static function isSecret(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::EXACT, true)) {
            return true;
        }

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
