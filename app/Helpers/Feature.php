<?php

namespace App\Helpers;

/**
 * Feature Flag Helper
 *
 * Permite verificar si una feature está habilitada o no.
 * Utiliza cache automático para mejorar el rendimiento.
 */
class Feature
{
    /**
     * Cache de features verificadas
     *
     * @var array<string, bool>
     */
    protected static array $cache = [];

    /**
     * Verifica si una feature está habilitada
     *
     * @param  string  $feature  Nombre de la feature
     * @return bool True si está habilitada, false en caso contrario
     */
    public static function enabled(string $feature): bool
    {
        // Verificar cache primero
        if (isset(static::$cache[$feature])) {
            return static::$cache[$feature];
        }

        // Obtener configuración de la feature
        $config = config("features.{$feature}", []);

        // Si no existe la configuración, retornar false
        if (empty($config)) {
            static::$cache[$feature] = false;

            return false;
        }

        // Obtener el valor de 'enabled' (por defecto false)
        $enabled = $config['enabled'] ?? false;

        // Guardar en cache
        static::$cache[$feature] = (bool) $enabled;

        return static::$cache[$feature];
    }

    /**
     * Verifica si una feature está deshabilitada
     *
     * @param  string  $feature  Nombre de la feature
     * @return bool True si está deshabilitada, false en caso contrario
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
     * Obtiene todas las features disponibles
     *
     * @return array<string, array{enabled: bool, description: string|null}>
     */
    public static function all(): array
    {
        $features = config('features', []);

        return array_map(function ($config) {
            return [
                'enabled' => $config['enabled'] ?? false,
                'description' => $config['description'] ?? null,
            ];
        }, $features);
    }

    /**
     * Limpia el cache de features
     * Útil para testing o cuando se cambian features dinámicamente
     */
    public static function clearCache(): void
    {
        static::$cache = [];
    }
}
