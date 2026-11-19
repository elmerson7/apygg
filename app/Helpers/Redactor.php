<?php

namespace App\Helpers;

/**
 * Redactor
 *
 * Oculta valores sensibles (contraseñas, tokens, secretos...) de cualquier
 * estructura antes de guardarla en un log. Recorre arrays anidados.
 *
 * Las listas son configurables en config/logging.php:
 * - `redact_keys`: nombres de clave exactos (sin distinguir mayúsculas).
 * - `redact_key_fragments`: fragmentos; si la clave los contiene, se oculta.
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    /**
     * Devuelve el dato con los valores sensibles reemplazados por MASK.
     */
    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $result[$key] = self::MASK;
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, array_map('strtolower', (array) config('logging.redact_keys', [])), true)) {
            return true;
        }

        foreach ((array) config('logging.redact_key_fragments', []) as $fragment) {
            if ($fragment !== '' && str_contains($key, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }
}
