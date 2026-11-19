<?php

namespace App\Support\Sequences;

/**
 * Formato de un número de secuencia. Pura y sin estado.
 */
final class SequenceNumber
{
    /**
     * `{year}` del prefijo se sustituye por el periodo (o, si no hay, por `$fallbackYear`).
     * Sin prefijo ni relleno devuelve el entero tal cual.
     */
    public static function format(int $value, ?string $prefix, int $padding, string $period = '', ?string $fallbackYear = null): string|int
    {
        $prefix ??= '';

        if ($prefix === '' && $padding <= 0) {
            return $value;
        }

        $year = $period !== '' ? $period : ($fallbackYear ?? date('Y'));

        return str_replace('{year}', $year, $prefix).str_pad((string) $value, max(0, $padding), '0', STR_PAD_LEFT);
    }
}
