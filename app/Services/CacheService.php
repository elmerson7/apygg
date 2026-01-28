<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * CacheService
 *
 * Servicio centralizado para operaciones de caché con soporte para tags,
 * invalidación selectiva y métricas.
 */
class CacheService
{
    /**
     * Tags activos para la operación actual
     */
    protected array $tags = [];

    /**
     * Prefijo por defecto para todas las keys
     */
    protected static string $prefix = 'apygg';

    /**
     * TTLs por defecto (en segundos)
     */
    protected static array $defaultTtls = [
        'user' => 3600,        // 1 hora
        'entity' => 7200,      // 2 horas
        'search' => 1800,      // 30 minutos
        'default' => 3600,     // 1 hora
    ];

    /**
     * Crear instancia con tags
     */
    public static function tag(string ...$tags): self
    {
        $instance = new self;
        $instance->tags = $tags;

        return $instance;
    }

    /**
     * Obtener valor del caché
     *
     * @param  mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $fullKey = self::buildKey($key);
        $value = null;

        if (! empty(self::getActiveTags())) {
            $value = Cache::tags(self::getActiveTags())->get($fullKey, $default);
        } else {
            $value = Cache::get($fullKey, $default);
        }

        // Trackear hit/miss
        self::trackCacheAccess($value !== $default || Cache::has($fullKey));

        return $value;
    }

    /**
     * Guardar valor en caché
     *
     * @param  mixed  $value
     * @param  int|null  $ttl  Tiempo de vida en segundos (null = usar default)
     */
    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        $fullKey = self::buildKey($key);
        $ttl = $ttl ?? self::$defaultTtls['default'];

        if (! empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->put($fullKey, $value, $ttl);
        }

        return Cache::put($fullKey, $value, $ttl);
    }

    /**
     * Eliminar valor del caché
     */
    public static function forget(string $key): bool
    {
        $fullKey = self::buildKey($key);

        if (! empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->forget($fullKey);
        }

        return Cache::forget($fullKey);
    }

    /**
     * Obtener valor o calcularlo y guardarlo
     *
     * @return mixed
     */
    public static function remember(string $key, ?int $ttl, callable $callback)
    {
        $fullKey = self::buildKey($key);
        $ttl = $ttl ?? self::$defaultTtls['default'];

        // Verificar si existe antes de remember para trackear hit/miss
        $exists = ! empty(self::getActiveTags())
            ? Cache::tags(self::getActiveTags())->has($fullKey)
            : Cache::has($fullKey);

        $value = ! empty(self::getActiveTags())
            ? Cache::tags(self::getActiveTags())->remember($fullKey, $ttl, $callback)
            : Cache::remember($fullKey, $ttl, $callback);

        // Trackear hit si existía, miss si no
        self::trackCacheAccess($exists);

        return $value;
    }

    /**
     * Invalidar todas las keys con un tag específico
     */
    public static function forgetTag(string $tag): bool
    {
        try {
            return Cache::tags([$tag])->flush();
        } catch (\Exception $e) {
            // Si el driver no soporta tags, intentar invalidación manual
            return self::flushByTag($tag);
        }
    }

    /**
     * Invalidar múltiples tags
     */
    public static function forgetTags(array $tags): bool
    {
        try {
            return Cache::tags($tags)->flush();
        } catch (\Exception $e) {
            $success = true;
            foreach ($tags as $tag) {
                $success = $success && self::flushByTag($tag);
            }

            return $success;
        }
    }

    /**
     * Cache de usuario con tag automático
     */
    public static function rememberUser(string $userId, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['user'];
        $key = "user:{$userId}";
        $tag = "user:{$userId}";

        // Verificar si existe antes de remember para trackear hit/miss
        $exists = Cache::tags([$tag])->has($key);
        $value = Cache::tags([$tag])->remember($key, $ttl, $callback);

        // Trackear hit si existía, miss si no
        self::trackCacheAccess($exists);

        return $value;
    }

    /**
     * Cache de entidad (roles, permissions, etc.)
     *
     * @param  string  $entity  Nombre de la entidad (ej: 'roles', 'permissions')
     */
    public static function rememberEntity(string $entity, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['entity'];
        $key = "entity:{$entity}";
        $tag = "entity:{$entity}";

        // Verificar si existe antes de remember para trackear hit/miss
        $exists = Cache::tags([$tag])->has($key);
        $value = Cache::tags([$tag])->remember($key, $ttl, $callback);

        // Trackear hit si existía, miss si no
        self::trackCacheAccess($exists);

        return $value;
    }

    /**
     * Cache de búsqueda
     *
     * @param  string  $query  Término de búsqueda
     * @param  array  $filters  Filtros adicionales
     */
    public static function rememberSearch(string $query, array $filters, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['search'];
        $hash = md5($query.serialize($filters));
        $key = "search:{$hash}";
        $tag = 'searches';

        // Verificar si existe antes de remember para trackear hit/miss
        $exists = Cache::tags([$tag])->has($key);
        $value = Cache::tags([$tag])->remember($key, $ttl, $callback);

        // Trackear hit si existía, miss si no
        self::trackCacheAccess($exists);

        return $value;
    }

    /**
     * Obtener todas las métricas del caché
     */
    public static function getAllMetrics(): array
    {
        $metrics = [
            'driver' => config('cache.default'),
            'prefix' => self::$prefix,
            'hit_rate' => 0,
            'memory_used' => '0MB',
            'keys_count' => 0,
            'tags_count' => 0,
        ];

        // Intentar obtener métricas de Redis si está disponible
        if (config('cache.default') === 'redis') {
            try {
                $redis = Redis::connection('cache');
                $info = $redis->info('memory');

                $metrics['memory_used'] = self::formatBytes($info['used_memory'] ?? 0);
                $metrics['keys_count'] = $redis->dbsize();

                // Calcular hit rate (requiere monitoreo activo)
                $metrics['hit_rate'] = self::calculateHitRate();
            } catch (\Exception $e) {
                // Si Redis no está disponible, usar valores por defecto
            }
        }

        return $metrics;
    }

    /**
     * Limpiar todo el caché
     */
    public static function flush(): bool
    {
        return Cache::flush();
    }

    /**
     * Verificar si una key existe en caché
     */
    public static function has(string $key): bool
    {
        $fullKey = self::buildKey($key);

        if (! empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->has($fullKey);
        }

        return Cache::has($fullKey);
    }

    /**
     * Obtener múltiples keys a la vez
     */
    public static function getMultiple(array $keys): array
    {
        $fullKeys = array_map(fn ($key) => self::buildKey($key), $keys);

        if (! empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->many($fullKeys);
        }

        return Cache::many($fullKeys);
    }

    /**
     * Guardar múltiples valores a la vez
     *
     * @param  array  $values  Array asociativo [key => value]
     */
    public static function setMultiple(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::$defaultTtls['default'];
        $fullValues = [];

        foreach ($values as $key => $value) {
            $fullValues[self::buildKey($key)] = $value;
        }

        if (! empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->putMany($fullValues, $ttl);
        }

        return Cache::putMany($fullValues, $ttl);
    }

    /**
     * Construir key completa con prefijo
     */
    protected static function buildKey(string $key): string
    {
        return self::$prefix.':'.$key;
    }

    /**
     * Obtener tags activos del contexto actual
     */
    protected static function getActiveTags(): array
    {
        // Esto se puede mejorar usando un contexto compartido o singleton
        return [];
    }

    /**
     * Invalidar por tag manualmente (fallback)
     */
    protected static function flushByTag(string $tag): bool
    {
        if (config('cache.default') !== 'redis') {
            return false;
        }

        try {
            $redis = Redis::connection('cache');
            $pattern = self::buildKey("*:{$tag}:*");
            $keys = $redis->keys($pattern);

            if (! empty($keys)) {
                $redis->del($keys);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Invalidar cache por patrón (invalidación masiva)
     *
     * Ejemplos:
     * - forgetPattern('user:*') - Invalida todo el cache de usuarios
     * - forgetPattern('*:permissions') - Invalida todo el cache de permisos
     * - forgetPattern('user:123:*') - Invalida todo el cache del usuario 123
     *
     * @param  string  $pattern  Patrón con wildcards (*)
     */
    public static function forgetPattern(string $pattern): int
    {
        if (config('cache.default') !== 'redis') {
            return 0;
        }

        try {
            $redis = Redis::connection('cache');
            $fullPattern = self::buildKey($pattern);
            $keys = $redis->keys($fullPattern);

            if (empty($keys)) {
                return 0;
            }

            // Redis KEYS puede ser lento, pero es necesario para patrones
            // En producción, considerar usar SCAN para mejor performance
            $deleted = $redis->del($keys);

            return $deleted;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Invalidar cache por patrón usando SCAN (más eficiente para grandes volúmenes)
     *
     * @param  string  $pattern  Patrón con wildcards (*)
     * @param  int  $count  Número de elementos a escanear por iteración
     */
    public static function forgetPatternScan(string $pattern, int $count = 100): int
    {
        if (config('cache.default') !== 'redis') {
            return 0;
        }

        try {
            $redis = Redis::connection('cache');
            $fullPattern = self::buildKey($pattern);
            $cursor = 0;
            $totalDeleted = 0;

            do {
                // Redis SCAN retorna [cursor, keys] donde cursor es int
                $result = $redis->scan($cursor, $fullPattern, $count);

                if (! is_array($result) || count($result) !== 2) {
                    break;
                }

                /** @var int $cursor */
                /** @var array<string> $keys */
                [$cursor, $keys] = $result;

                if (! empty($keys)) {
                    $deleted = $redis->del($keys);
                    $totalDeleted += $deleted;
                }
            } while ($cursor > 0);

            return $totalDeleted;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Trackear acceso al caché (hit o miss)
     *
     * @param  bool  $isHit  true si fue hit, false si fue miss
     */
    protected static function trackCacheAccess(bool $isHit): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        try {
            $redis = Redis::connection('cache');
            $key = $isHit ? 'cache:hits' : 'cache:misses';
            $redis->incr($key);

            // Expirar contadores después de 24 horas para resetear métricas diarias
            $redis->expire('cache:hits', 86400);
            $redis->expire('cache:misses', 86400);
        } catch (\Exception $e) {
            // Silenciar errores de tracking para no afectar la funcionalidad principal
        }
    }

    /**
     * Resetear contadores de métricas
     */
    public static function resetMetrics(): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        try {
            $redis = Redis::connection('cache');
            $redis->del('cache:hits', 'cache:misses');
        } catch (\Exception $e) {
            // Silenciar errores
        }
    }

    /**
     * Calcular hit rate del caché
     */
    protected static function calculateHitRate(): float
    {
        try {
            $redis = Redis::connection('cache');
            $hits = (int) ($redis->get('cache:hits') ?? 0);
            $misses = (int) ($redis->get('cache:misses') ?? 0);

            $total = $hits + $misses;
            if ($total === 0) {
                return 0.0;
            }

            return round(($hits / $total) * 100, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Formatear bytes a formato legible
     */
    protected static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).$units[$pow];
    }

    /**
     * Configurar TTL por defecto para un tipo
     */
    public static function setDefaultTtl(string $type, int $ttl): void
    {
        self::$defaultTtls[$type] = $ttl;
    }

    /**
     * Obtener TTL por defecto para un tipo
     */
    public static function getDefaultTtl(string $type): int
    {
        return self::$defaultTtls[$type] ?? self::$defaultTtls['default'];
    }
}
