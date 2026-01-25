<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * CacheService
 * 
 * Servicio centralizado para operaciones de caché con soporte para tags,
 * invalidación selectiva y métricas.
 * 
 * @package App\Services
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
        $instance = new self();
        $instance->tags = $tags;
        return $instance;
    }

    /**
     * Obtener valor del caché
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $fullKey = self::buildKey($key);
        
        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->get($fullKey, $default);
        }

        return Cache::get($fullKey, $default);
    }

    /**
     * Guardar valor en caché
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl Tiempo de vida en segundos (null = usar default)
     * @return bool
     */
    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        $fullKey = self::buildKey($key);
        $ttl = $ttl ?? self::$defaultTtls['default'];

        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->put($fullKey, $value, $ttl);
        }

        return Cache::put($fullKey, $value, $ttl);
    }

    /**
     * Eliminar valor del caché
     *
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        $fullKey = self::buildKey($key);

        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->forget($fullKey);
        }

        return Cache::forget($fullKey);
    }

    /**
     * Obtener valor o calcularlo y guardarlo
     *
     * @param string $key
     * @param int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    public static function remember(string $key, ?int $ttl, callable $callback)
    {
        $fullKey = self::buildKey($key);
        $ttl = $ttl ?? self::$defaultTtls['default'];

        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->remember($fullKey, $ttl, $callback);
        }

        return Cache::remember($fullKey, $ttl, $callback);
    }

    /**
     * Invalidar todas las keys con un tag específico
     *
     * @param string $tag
     * @return bool
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
     *
     * @param array $tags
     * @return bool
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
     *
     * @param string $userId
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public static function rememberUser(string $userId, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['user'];
        $key = "user:{$userId}";
        $tag = "user:{$userId}";

        return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }

    /**
     * Cache de entidad (roles, permissions, etc.)
     *
     * @param string $entity Nombre de la entidad (ej: 'roles', 'permissions')
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public static function rememberEntity(string $entity, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['entity'];
        $key = "entity:{$entity}";
        $tag = "entity:{$entity}";

        return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }

    /**
     * Cache de búsqueda
     *
     * @param string $query Término de búsqueda
     * @param array $filters Filtros adicionales
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public static function rememberSearch(string $query, array $filters, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::$defaultTtls['search'];
        $hash = md5($query . serialize($filters));
        $key = "search:{$hash}";
        $tag = 'searches';

        return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }

    /**
     * Obtener todas las métricas del caché
     *
     * @return array
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
     *
     * @return bool
     */
    public static function flush(): bool
    {
        return Cache::flush();
    }

    /**
     * Verificar si una key existe en caché
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        $fullKey = self::buildKey($key);

        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->has($fullKey);
        }

        return Cache::has($fullKey);
    }

    /**
     * Obtener múltiples keys a la vez
     *
     * @param array $keys
     * @return array
     */
    public static function getMultiple(array $keys): array
    {
        $fullKeys = array_map(fn($key) => self::buildKey($key), $keys);
        
        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->many($fullKeys);
        }

        return Cache::many($fullKeys);
    }

    /**
     * Guardar múltiples valores a la vez
     *
     * @param array $values Array asociativo [key => value]
     * @param int|null $ttl
     * @return bool
     */
    public static function setMultiple(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::$defaultTtls['default'];
        $fullValues = [];
        
        foreach ($values as $key => $value) {
            $fullValues[self::buildKey($key)] = $value;
        }

        if (!empty(self::getActiveTags())) {
            return Cache::tags(self::getActiveTags())->putMany($fullValues, $ttl);
        }

        return Cache::putMany($fullValues, $ttl);
    }

    /**
     * Construir key completa con prefijo
     *
     * @param string $key
     * @return string
     */
    protected static function buildKey(string $key): string
    {
        return self::$prefix . ':' . $key;
    }

    /**
     * Obtener tags activos del contexto actual
     *
     * @return array
     */
    protected static function getActiveTags(): array
    {
        // Esto se puede mejorar usando un contexto compartido o singleton
        return [];
    }

    /**
     * Invalidar por tag manualmente (fallback)
     *
     * @param string $tag
     * @return bool
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
            
            if (!empty($keys)) {
                $redis->del($keys);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Calcular hit rate del caché
     *
     * @return float
     */
    protected static function calculateHitRate(): float
    {
        // Esto requiere implementar contadores de hits/misses
        // Por ahora retornamos un valor por defecto
        // Se puede implementar con Redis counters o métricas de Laravel
        
        try {
            $redis = Redis::connection('cache');
            $hits = $redis->get('cache:hits') ?? 0;
            $misses = $redis->get('cache:misses') ?? 0;
            
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
     *
     * @param int $bytes
     * @return string
     */
    protected static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . $units[$pow];
    }

    /**
     * Configurar TTL por defecto para un tipo
     *
     * @param string $type
     * @param int $ttl
     * @return void
     */
    public static function setDefaultTtl(string $type, int $ttl): void
    {
        self::$defaultTtls[$type] = $ttl;
    }

    /**
     * Obtener TTL por defecto para un tipo
     *
     * @param string $type
     * @return int
     */
    public static function getDefaultTtl(string $type): int
    {
        return self::$defaultTtls[$type] ?? self::$defaultTtls['default'];
    }
}
