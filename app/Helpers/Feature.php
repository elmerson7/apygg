<?php

namespace App\Helpers;

use App\Contracts\FeatureFlagServiceInterface;

/**
 * Feature Flag Helper
 *
 * Fachada estática de comodidad sobre `FeatureFlagServiceInterface` (flags en BD con override por
 * tenant/usuario y despliegue porcentual; ver docs/settings-and-flags.md). Donde se pueda inyectar
 * (controllers, servicios, jobs), inyecta la interfaz; el helper es para código que no lo permite
 * (vistas, `config`, closures de rutas). No guarda estado propio (Octane).
 */
class Feature
{
    /**
     * Verifica si una feature está habilitada para el tenant del contexto y el usuario autenticado
     *
     * @param  string  $feature  Nombre de la feature
     * @return bool True si está habilitada, false en caso contrario (o si no existe)
     */
    public static function enabled(string $feature): bool
    {
        return app(FeatureFlagServiceInterface::class)->enabled($feature);
    }

    /**
     * Verifica si una feature está deshabilitada
     */
    public static function disabled(string $feature): bool
    {
        return ! static::enabled($feature);
    }

    /**
     * Obtiene la descripción de una feature
     *
     * @param  string  $feature  Nombre de la feature
     * @return string|null Descripción de la feature o null si no existe
     */
    public static function description(string $feature): ?string
    {
        $config = config("features.{$feature}", []);

        return $config['description'] ?? null;
    }

    /**
     * Obtiene todas las features declaradas, evaluadas para el contexto actual
     *
     * @return array<string, array{enabled: bool, description: string|null}>
     */
    public static function all(): array
    {
        return app(FeatureFlagServiceInterface::class)->all();
    }
}
