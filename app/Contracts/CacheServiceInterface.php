<?php

namespace App\Contracts;

/**
 * CacheServiceInterface
 *
 * Contrato para el servicio de caché.
 */
interface CacheServiceInterface
{
    /**
     * Crear instancia con tags
     *
     * @param  string  ...$tags  Tags para agrupar keys
     * @return static
     */
    public static function tag(string ...$tags): static;

    /**
     * Obtener valor del caché
     *
     * @param  string  $key  Key a obtener
     * @param  mixed   $default  Valor por defecto si no existe
     * @return mixed
     */
    public static function get(string $key, $default = null);

    /**
     * Guardar valor en caché
     *
     * @param  string  $key  Key a guardar
     * @param  mixed   $value  Valor a guardar
     * @param  int|null  $ttl  Tiempo de vida en segundos (null = usar default)
     * @return bool  True si se guardó exitosamente
     */
    public static function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Eliminar valor del caché
     *
     * @param  string  $key  Key a eliminar
     * @return bool  True si se eliminó exitosamente
     */
    public static function forget(string $key): bool;

    /**
     * Obtener valor o calcularlo y guardarlo
     *
     * @param  string   $key  Key a obtener o calcular
     * @param  int|null $ttl  Tiempo de vida en segundos (null = usar default)
     * @param  callable $callback  Función para calcular el valor si no existe
     * @return mixed
     */
    public static function remember(string $key, ?int $ttl, callable $callback);

    /**
     * Invalidar todas las keys con un tag específico
     *
     * @param  string  $tag  Tag a invalidar
     * @return bool  True si se invalidó exitosamente
     */
    public static function forgetTag(string $tag): bool;

    /**
     * Invalidar múltiples tags
     *
     * @param  array  $tags  Tags a invalidar
     * @return bool  True si se invalidó exitosamente
     */
    public static function forgetTags(array $tags): bool;

    /**
     * Cache de usuario con tag automático
     *
     * @param  string   $userId  ID del usuario
     * @param  callable $callback  Función para calcular el valor si no existe
     * @param  int|null $ttl     Tiempo de vida en segundos (null = usar default)
     * @return mixed
     */
    public static function rememberUser(string $userId, callable $callback, ?int $ttl = null): mixed;

    /**
     * Cache de entidad (roles, permissions, etc.)
     *
     * @param  string   $entity  Nombre de la entidad (ej: 'roles', 'permissions')
     * @param  callable $callback  Función para calcular el valor si no existe
     * @param  int|null $ttl     Tiempo de vida en segundos (null = usar default)
     * @return mixed
     */
    public static function rememberEntity(string $entity, callable $callback, ?int $ttl = null): mixed;

    /**
     * Cache de búsqueda
     *
     * @param  string   $query  Término de búsqueda
     * @param  array    $filters  Filtros adicionales
     * @param  callable $callback  Función para calcular el valor si no existe
     * @param  int|null $ttl      Tiempo de vida en segundos (null = usar default)
     * @return mixed
     */
    public static function rememberSearch(string $query, array $filters, callable $callback, ?int $ttl = null): mixed;

    /**
     * Obtener todas las métricas del caché
     *
     * @return array  Métricas del caché [driver, prefix, hit_rate, memory_used, keys_count, tags_count]
     */
    public static function getAllMetrics(): array;

    /**
     * Limpiar todo el caché
     *
     * @return bool  True si se limpió exitosamente
     */
    public static function flush(): bool;

    /**
     * Verificar si una key existe en caché
     *
     * @param  string  $key  Key a verificar
     * @return bool  True si existe
     */
    public static function has(string $key): bool;

    /**
     * Obtener múltiples keys a la vez
     *
     * @param  array  $keys  Keys a obtener
     * @return array  Valores asociados a las keys
     */
    public static function getMultiple(array $keys): array;

    /**
     * Guardar múltiples valores a la vez
     *
     * @param  array  $values  Array asociativo [key => value]
     * @param  int|null $ttl   Tiempo de vida en segundos (null = usar default)
     * @return bool  True si se guardó exitosamente
     */
    public static function setMultiple(array $values, ?int $ttl = null): bool;

    /**
     * Invalidar cache por patrón (invalidación masiva)
     *
     * @param  string  $pattern  Patrón con wildcards (*)
     * @return int  Número de keys eliminadas
     */
    public static function forgetPattern(string $pattern): int;

    /**
     * Invalidar cache por patrón usando SCAN (más eficiente para grandes volúmenes)
     *
     * @param  string  $pattern  Patrón con wildcards (*)
     * @param  int     $count    Número de elementos a escanear por iteración
     * @return int     Número de keys eliminadas
     */
    public static function forgetPatternScan(string $pattern, int $count = 100): int;

    /**
     * Trackear acceso al caché (hit o miss)
     *
     * @param  bool  $isHit  true si fue hit, false si fue miss
     */
    public static function trackCacheAccess(bool $isHit): void;

    /**
     * Resetear contadores de métricas
     */
    public static function resetMetrics(): void;

    /**
     * Calcular hit rate del caché
     *
     * @return float  Hit rate como porcentaje
     */
    public static function calculateHitRate(): float;

    /**
     * Formatear bytes a formato legible
     *
     * @param  int  $bytes  Bytes a formatear
     * @return string  Bytes formateados (ej: "1.5MB")
     */
    public static function formatBytes(int $bytes): string;

    /**
     * Configurar TTL por defecto para un tipo
     *
     * @param  string $type  Tipo de caché ('user', 'entity', 'search', 'default')
     * @param  int    $ttl   TTL en segundos
     */
    public static function setDefaultTtl(string $type, int $ttl): void;

    /**
     * Obtener TTL por defecto para un tipo
     *
     * @param  string $type  Tipo de caché ('user', 'entity', 'search', 'default')
     * @return int    TTL en segundos
     */
    public static function getDefaultTtl(string $type): int;
}