<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * CacheListCommand
 *
 * Comando para listar y ver el estado del cache.
 * Muestra keys válidas, TTL, tamaño y contenido.
 */
class CacheListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:list 
                            {--pattern=* : Patrón para filtrar keys (ej: user:*)}
                            {--tag= : Filtrar por tag específico}
                            {--limit=50 : Límite de keys a mostrar}
                            {--ttl : Mostrar TTL de cada key}
                            {--size : Mostrar tamaño de cada key}
                            {--value : Mostrar valores de las keys}
                            {--stats : Mostrar solo estadísticas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listar keys del cache con información detallada';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('cache.default') !== 'redis') {
            $this->error('Este comando solo funciona con Redis como driver de cache');

            return Command::FAILURE;
        }

        $pattern = $this->option('pattern');
        $tag = $this->option('tag');
        $limit = (int) $this->option('limit');
        $showTtl = $this->option('ttl');
        $showSize = $this->option('size');
        $showValue = $this->option('value');
        $showStats = $this->option('stats');

        try {
            $redis = Redis::connection('cache');

            // Construir patrón de búsqueda
            $searchPattern = $this->buildSearchPattern($pattern, $tag);

            if ($showStats) {
                return $this->showStats($redis, $searchPattern);
            }

            // Obtener keys
            $keys = $this->getKeys($redis, $searchPattern, $limit);

            if (empty($keys)) {
                $this->info('No se encontraron keys con el patrón especificado');

                return Command::SUCCESS;
            }

            // Mostrar información
            $this->displayKeys($redis, $keys, $showTtl, $showSize, $showValue);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al listar cache: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Construir patrón de búsqueda
     */
    protected function buildSearchPattern(?array $pattern, ?string $tag): string
    {
        if ($tag) {
            // Buscar keys con tag específico (formato de Laravel Redis tags)
            return '*tag:'.$tag.'*';
        }

        if (! empty($pattern)) {
            $patternStr = implode('*', $pattern);

            // Si el patrón ya tiene *, usarlo directamente
            if (str_contains($patternStr, '*')) {
                return '*apygg:'.$patternStr;
            }

            // Si no tiene *, buscar en cualquier parte del nombre
            return '*apygg:*'.$patternStr.'*';
        }

        // Buscar todas las keys (Redis agregará su prefijo automáticamente)
        return '*';
    }

    /**
     * Obtener keys del cache
     */
    protected function getKeys($redis, string $pattern, int $limit): array
    {
        // Usar KEYS directamente (más simple, aunque puede ser lento con muchas keys)
        // En producción con muchas keys, considerar usar SCAN con mejor manejo
        $allKeys = $redis->keys($pattern);
        $keys = [];
        $count = 0;

        foreach ($allKeys as $key) {
            // Filtrar keys de tags de Laravel y entries (no son datos reales)
            // Incluir todas las keys que contengan 'apygg:' (datos reales)
            // Las keys con tags pueden no existir directamente pero son válidas
            if (! str_contains($key, ':tag:') &&
                ! str_contains($key, ':entries') &&
                str_contains($key, 'apygg:')) {
                $keys[] = $key;
                $count++;

                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $keys;
    }

    /**
     * Mostrar estadísticas del cache
     */
    protected function showStats($redis, string $pattern): int
    {
        $this->info('=== Estadísticas del Cache ===');
        $this->newLine();

        // Contar keys totales
        $allKeys = $this->getKeys($redis, $pattern, 10000);
        $totalKeys = count($allKeys);

        $this->line("Total de keys: {$totalKeys}");

        // Agrupar por tipo
        $byType = [];
        foreach ($allKeys as $key) {
            $type = $this->extractType($key);
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        $this->newLine();
        $this->info('Keys por tipo:');
        foreach ($byType as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        // Memoria usada
        $info = $redis->info('memory');
        $memoryUsed = $this->formatBytes($info['used_memory'] ?? 0);
        $this->newLine();
        $this->line("Memoria usada: {$memoryUsed}");

        return Command::SUCCESS;
    }

    /**
     * Extraer tipo de key
     */
    protected function extractType(string $key): string
    {
        // Buscar apygg: en la key
        if (preg_match('/apygg:([^:]+)/', $key, $matches)) {
            return $matches[1];
        }

        return 'other';
    }

    /**
     * Mostrar keys con información
     */
    protected function displayKeys($redis, array $keys, bool $showTtl, bool $showSize, bool $showValue): void
    {
        $this->info('=== Keys del Cache ===');
        $this->newLine();

        $headers = ['Key'];
        if ($showTtl) {
            $headers[] = 'TTL';
        }
        if ($showSize) {
            $headers[] = 'Tamaño';
        }
        if ($showValue) {
            $headers[] = 'Valor';
        }

        $rows = [];

        foreach ($keys as $key) {
            $row = [$this->formatKey($key)];

            if ($showTtl) {
                // Usar la key completa para verificar TTL
                $ttl = $redis->ttl($key);
                if ($ttl === -1) {
                    $row[] = '∞ (sin expiración)';
                } elseif ($ttl === -2) {
                    // Key puede estar en un tag, mostrar como "en tag"
                    $row[] = 'En tag';
                } elseif ($ttl > 0) {
                    $row[] = $this->formatTtl($ttl);
                } else {
                    $row[] = '0s (expirado)';
                }
            }

            if ($showSize) {
                $size = $redis->strlen($key) ?? 0;
                $row[] = $this->formatBytes($size);
            }

            if ($showValue) {
                // Intentar obtener el valor
                // Nota: Las keys con tags no se pueden acceder directamente con get()
                // Necesitarían el tag específico, por lo que mostramos <en tag>
                $value = $redis->get($key);
                if ($value === false || $value === null) {
                    $row[] = '<en tag - usar Cache::tags()>';
                } else {
                    $row[] = $this->formatValue($value);
                }
            }

            $rows[] = $row;
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->info('Total: '.count($keys).' keys');
    }

    /**
     * Formatear key para mostrar
     */
    protected function formatKey(string $key): string
    {
        // Extraer solo la parte después de apygg:
        if (preg_match('/apygg:([^:]+(?::.+)?)/', $key, $matches)) {
            $key = $matches[1];
        }

        // Limitar longitud
        if (strlen($key) > 60) {
            return substr($key, 0, 57).'...';
        }

        return $key;
    }

    /**
     * Formatear TTL
     */
    protected function formatTtl(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return round($seconds / 60, 1).'m';
        }

        return round($seconds / 3600, 1).'h';
    }

    /**
     * Formatear valor
     */
    protected function formatValue($value): string
    {
        if (is_null($value)) {
            return '<null>';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (strlen($json) > 100) {
            return substr($json, 0, 97).'...';
        }

        return $json;
    }

    /**
     * Formatear bytes
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).$units[$pow];
    }

    /**
     * Extraer key de cache sin prefijos
     */
    protected function extractCacheKey(string $fullKey): string
    {
        // Remover prefijos de Redis y Laravel
        $key = preg_replace('/^.*apygg:/', '', $fullKey);

        return $key;
    }
}
