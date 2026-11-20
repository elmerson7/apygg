<?php

namespace App\Support;

/**
 * Resumen legible ("Chrome en Windows") de un User-Agent para listar sesiones. Sin estado.
 */
final class DeviceLabel
{
    public static function from(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $navegador = match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $userAgent) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $userAgent) => 'Opera',
            (bool) preg_match('/Firefox\/|FxiOS/', $userAgent) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/', $userAgent) => 'Chrome',
            (bool) preg_match('/Safari\//', $userAgent) => 'Safari',
            (bool) preg_match('/okhttp|Dalvik/i', $userAgent) => 'App Android',
            (bool) preg_match('/CFNetwork|Darwin/', $userAgent) => 'App iOS',
            default => null,
        };

        $sistema = match (true) {
            (bool) preg_match('/Android/', $userAgent) => 'Android',
            (bool) preg_match('/iPhone|iPad|iOS/', $userAgent) => 'iOS',
            (bool) preg_match('/Windows/', $userAgent) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/', $userAgent) => 'macOS',
            (bool) preg_match('/Linux|X11/', $userAgent) => 'Linux',
            default => null,
        };

        $partes = array_filter([$navegador, $sistema]);

        if ($partes === []) {
            return mb_substr(trim($userAgent), 0, 120);
        }

        return mb_substr(implode(' en ', $partes), 0, 120);
    }
}
