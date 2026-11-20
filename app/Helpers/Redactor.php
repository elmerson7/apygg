<?php

namespace App\Helpers;

/**
 * Redactor — único punto de redacción de datos personales y secretos.
 *
 * Lo usan los loggers de BD (API, actividad, seguridad), el processor de Monolog
 * (todos los canales de archivo), el scrubber de Sentry y `Pretty::json()` del back-office.
 *
 * Dos niveles:
 * - Por NOMBRE de clave (`redact.keys` exacto / `redact.key_fragments` contenido): el valor se
 *   sustituye entero por MASK (o la clave se elimina en modo `remove`).
 * - Por PATRÓN dentro de cualquier texto (`redact.patterns`): emails, tarjetas (validadas con
 *   Luhn), teléfonos internacionales (+...), JWT y `Bearer ...`. En modo `mask` se conserva una
 *
 *   pista (`j***@d***.com`, `****1234`); en modo `remove` se sustituye por MASK.
 *
 * Recorrido recursivo con límite de profundidad (`redact.max_depth`) y de longitud de cadena
 * (`redact.max_string`). Sin estado: lee `config('logging.redact')` en cada llamada (Octane-safe).
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    public const DEPTH_MARK = '[MAX_DEPTH]';

    public const MODE_MASK = 'mask';

    public const MODE_REMOVE = 'remove';

    /**
     * Devuelve el dato con los valores sensibles reemplazados.
     *
     * @template T
     *
     * @param  T  $data
     * @return T|mixed
     */
    public static function redact(mixed $data): mixed
    {
        return self::walk($data, 0);
    }

    /**
     * Redacta patrones dentro de un texto (mensajes de log, cadenas sueltas).
     */
    public static function redactString(string $value): string
    {
        $max = (int) self::setting('max_string', 10000);
        if ($max > 0 && strlen($value) > $max) {
            $value = substr($value, 0, $max).'…[truncated]';
        }

        $patterns = (array) self::setting('patterns', []);
        if ($patterns === []) {
            return $value;
        }

        $remove = self::mode() === self::MODE_REMOVE;

        // Orden: JWT y Bearer primero (contienen puntos/dígitos que otros patrones confundirían).
        if (in_array('jwt', $patterns, true) && str_contains($value, 'eyJ')) {
            $value = (string) preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', self::MASK, $value);
        }
        if (in_array('bearer', $patterns, true) && stripos($value, 'bearer') !== false) {
            $value = (string) preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::MASK, $value);
        }
        if (in_array('email', $patterns, true) && str_contains($value, '@')) {
            $value = (string) preg_replace_callback(
                '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/',
                fn (array $m): string => $remove ? self::MASK : self::maskEmail($m[0]),
                $value,
            );
        }
        if (in_array('stripe', $patterns, true) && preg_match('/(?:sk|rk|pk|whsec)_|_secret_/', $value) === 1) {
            // Claves de Stripe (sk_live_..., rk_..., whsec_...) y client_secret de un PaymentIntent (pi_..._secret_...).
            $value = (string) preg_replace('/\b(?:(?:sk|rk|pk)_(?:live|test)_|whsec_)[A-Za-z0-9]{8,}|\bpi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/', self::MASK, $value);
        }
        $hasDigits = preg_match('/\d(?:[ ().\-]*\d){6}/', $value) === 1;
        if ($hasDigits && in_array('card', $patterns, true)) {
            $value = (string) preg_replace_callback(
                '/(?<![\d])(?:\d[ -]?){12,18}\d(?![\d])/',
                function (array $m) use ($remove): string {
                    $digits = (string) preg_replace('/\D/', '', $m[0]);
                    if (strlen($digits) < 13 || strlen($digits) > 19 || ! self::luhn($digits)) {
                        return $m[0];
                    }

                    return $remove ? self::MASK : '****'.substr($digits, -4);
                },
                $value,
            );
        }
        if ($hasDigits && in_array('phone', $patterns, true) && str_contains($value, '+')) {
            $value = (string) preg_replace_callback(
                '/(?<![\w+])\+\d[\d\s().\-]{6,17}\d/',
                function (array $m) use ($remove): string {
                    $digits = (string) preg_replace('/\D/', '', $m[0]);
                    if (strlen($digits) < 8 || strlen($digits) > 15) {
                        return $m[0];
                    }

                    return $remove ? self::MASK : '****'.substr($digits, -4);
                },
                $value,
            );
        }

        return $value;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1).'***@'.mb_substr($host, 0, 1).'***'.$tld;
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, array_map('strtolower', (array) self::setting('keys', [])), true)) {
            return true;
        }

        foreach ((array) self::setting('key_fragments', []) as $fragment) {
            if ($fragment !== '' && str_contains($key, strtolower((string) $fragment))) {
                return true;
            }
        }

        return false;
    }

    public static function mode(): string
    {
        return self::setting('mode', self::MODE_MASK) === self::MODE_REMOVE ? self::MODE_REMOVE : self::MODE_MASK;
    }

    private static function setting(string $key, mixed $default): mixed
    {
        return config('logging.redact.'.$key, $default);
    }

    private static function walk(mixed $data, int $depth): mixed
    {
        if (is_string($data)) {
            return self::redactString($data);
        }

        if (! is_array($data)) {
            return $data;
        }

        if ($depth >= (int) self::setting('max_depth', 8)) {
            return self::DEPTH_MARK;
        }

        $remove = self::mode() === self::MODE_REMOVE;
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                if (! $remove) {
                    $result[$key] = self::MASK;
                }

                continue;
            }
            $result[$key] = self::walk($value, $depth + 1);
        }

        return $result;
    }
}
