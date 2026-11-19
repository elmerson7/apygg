<?php

namespace App\Support\Features;

/**
 * Despliegue porcentual ESTABLE: el cubo (0-99) sale de un hash de flag + sujeto, nunca de
 * `rand()`. El mismo usuario/tenant cae siempre en el mismo cubo (entre peticiones, servidores
 * y reinicios), y subir el porcentaje solo AÑADE sujetos (el cubo no cambia).
 * Función pura: sin estado, segura con Octane.
 */
final class Rollout
{
    public static function bucket(string $featureKey, string $by, int $subjectId): int
    {
        return crc32($featureKey.'|'.$by.'|'.$subjectId) % 100;
    }

    public static function includes(string $featureKey, string $by, int $subjectId, int $percentage): bool
    {
        return self::bucket($featureKey, $by, $subjectId) < $percentage;
    }
}
