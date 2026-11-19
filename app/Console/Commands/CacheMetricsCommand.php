<?php

namespace App\Console\Commands;

use App\Services\CacheService;
use App\Services\LogService;
use Illuminate\Console\Command;

/**
 * @phpstan-type Recommendation array{priority: string, category: string, title: string, description: string, actions?: list<string>}
 */
class CacheMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:metrics 
                            {--alert-threshold=70 : Umbral mínimo de hit rate para alertas (%)}
                            {--reset : Resetear contadores de métricas}
                            {--json : Salida en formato JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitorear métricas de caché y generar alertas cuando el hit rate baja del umbral';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('reset')) {
            CacheService::resetMetrics();
            $this->info('Contadores de métricas reseteados.');

            return Command::SUCCESS;
        }

        $metrics = CacheService::getAllMetrics();
        $threshold = (float) $this->option('alert-threshold');
        $hitRate = $metrics['hit_rate'] ?? 0;

        // Generar recomendaciones
        $recommendations = $this->generateRecommendations($metrics, $hitRate);

        if ($this->option('json')) {
            $this->line(json_encode([
                'metrics' => $metrics,
                'recommendations' => $recommendations,
                'alert' => $hitRate < $threshold,
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        // Mostrar métricas
        $this->displayMetrics($metrics);

        // Verificar umbral y alertar si es necesario
        if ($hitRate < $threshold) {
            $this->alertLowHitRate($hitRate, $threshold, $recommendations);
        } else {
            $this->info("✅ Hit rate está por encima del umbral ({$threshold}%): {$hitRate}%");
        }

        // Mostrar recomendaciones
        if (! empty($recommendations)) {
            $this->displayRecommendations($recommendations);
        }

        return Command::SUCCESS;
    }

    /**
     * Mostrar métricas en formato legible
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function displayMetrics(array $metrics): void
    {
        $this->newLine();
        $this->info('📊 Métricas de Caché');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $hitRate = $metrics['hit_rate'] ?? 0;
        $hitRateColor = $hitRate >= 70 ? 'green' : ($hitRate >= 50 ? 'yellow' : 'red');

        $this->line("Driver: <fg=cyan>{$metrics['driver']}</>");
        $this->line("Prefijo: <fg=cyan>{$metrics['prefix']}</>");
        $this->line("Hit Rate: <fg={$hitRateColor}>{$hitRate}%</>");
        $this->line("Memoria Usada: <fg=cyan>{$metrics['memory_used']}</>");
        $this->line("Keys en Caché: <fg=cyan>{$metrics['keys_count']}</>");
        $this->line("Tags: <fg=cyan>{$metrics['tags_count']}</>");

        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Alertar cuando el hit rate está bajo
     *
     * @param  list<Recommendation>  $recommendations
     */
    protected function alertLowHitRate(float $hitRate, float $threshold, array $recommendations): void
    {
        $this->newLine();
        $this->error("⚠️  ALERTA: Hit rate está por debajo del umbral ({$threshold}%)");
        $this->error("   Hit rate actual: {$hitRate}%");
        $this->newLine();

        // Log crítico para monitoreo
        LogService::warning('Cache hit rate bajo el umbral', [
            'hit_rate' => $hitRate,
            'threshold' => $threshold,
            'metrics' => CacheService::getAllMetrics(),
            'recommendations' => $recommendations,
        ]);

        // Enviar a Sentry si está configurado (solo en producción)
        if (config('app.env') === 'production') {
            LogService::critical('Cache hit rate crítico', [
                'hit_rate' => $hitRate,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * Generar recomendaciones de optimización
     *
     * @param  array<string, mixed>  $metrics
     * @return list<Recommendation>
     */
    protected function generateRecommendations(array $metrics, float $hitRate): array
    {
        $recommendations = [];

        // Recomendaciones basadas en hit rate
        if ($hitRate < 50) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'hit_rate',
                'title' => 'Hit rate muy bajo (< 50%)',
                'description' => 'El caché no está siendo efectivo. Considera aumentar TTLs o revisar estrategia de invalidación.',
                'actions' => [
                    'Revisar TTLs de caché y aumentarlos si es apropiado',
                    'Verificar que la invalidación no sea demasiado agresiva',
                    'Considerar cachear más datos frecuentemente accedidos',
                    'Revisar si hay problemas de conectividad con Redis',
                ],
            ];
        } elseif ($hitRate < 70) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'hit_rate',
                'title' => 'Hit rate por debajo del umbral recomendado (< 70%)',
                'description' => 'El caché podría ser más efectivo con algunas optimizaciones.',
                'actions' => [
                    'Revisar patrones de acceso y ajustar TTLs según uso',
                    'Considerar implementar cache warming para datos críticos',
                    'Verificar que los datos más accedidos estén siendo cacheados',
                ],
            ];
        }

        // Recomendaciones basadas en memoria
        $memoryUsed = $metrics['memory_used'] ?? '0MB';
        $memoryMB = $this->parseMemoryToMB($memoryUsed);

        if ($memoryMB > 500) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'memory',
                'title' => 'Uso alto de memoria en caché (> 500MB)',
                'description' => 'El caché está usando mucha memoria. Considera optimizar.',
                'actions' => [
                    'Revisar TTLs y reducir para datos menos críticos',
                    'Implementar limpieza periódica de caché antiguo',
                    'Considerar usar compresión para valores grandes',
                    'Revisar si hay keys huérfanas o sin usar',
                ],
            ];
        }

        // Recomendaciones basadas en número de keys
        $keysCount = $metrics['keys_count'] ?? 0;

        if ($keysCount > 10000) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'keys',
                'title' => 'Gran cantidad de keys en caché (> 10,000)',
                'description' => 'Muchas keys pueden indicar fragmentación o falta de agrupación.',
                'actions' => [
                    'Considerar usar tags para agrupar keys relacionadas',
                    'Revisar si hay keys que deberían compartir el mismo TTL',
                    'Implementar limpieza periódica de keys expiradas',
                ],
            ];
        }

        // Recomendaciones generales si hit rate es bueno pero hay margen de mejora
        if ($hitRate >= 70 && $hitRate < 85) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'optimization',
                'title' => 'Oportunidad de optimización',
                'description' => 'El hit rate es bueno pero puede mejorarse aún más.',
                'actions' => [
                    'Analizar patrones de acceso para identificar datos frecuentes',
                    'Considerar aumentar TTLs para datos que raramente cambian',
                    'Implementar cache warming para datos críticos al inicio del día',
                ],
            ];
        }

        return $recommendations;
    }

    /**
     * Mostrar recomendaciones
     *
     * @param  list<Recommendation>  $recommendations
     */
    protected function displayRecommendations(array $recommendations): void
    {
        $this->newLine();
        $this->info('💡 Recomendaciones de Optimización');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        foreach ($recommendations as $index => $rec) {
            $priorityColor = match ($rec['priority']) {
                'high' => 'red',
                'medium' => 'yellow',
                'low' => 'cyan',
                default => 'white',
            };

            $this->newLine();
            $this->line("<fg={$priorityColor}>[{$rec['priority']}]</> <fg=white>{$rec['title']}</>");
            $this->line("   {$rec['description']}");

            if (! empty($rec['actions'])) {
                $this->line('   Acciones sugeridas:');
                foreach ($rec['actions'] as $action) {
                    $this->line("   • {$action}");
                }
            }
        }

        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Convertir memoria de formato legible a MB
     */
    protected function parseMemoryToMB(string $memory): float
    {
        $memory = trim(strtoupper($memory));
        $value = (float) preg_replace('/[^0-9.]/', '', $memory);

        if (str_contains($memory, 'GB')) {
            return $value * 1024;
        } elseif (str_contains($memory, 'MB')) {
            return $value;
        } elseif (str_contains($memory, 'KB')) {
            return $value / 1024;
        }

        return $value / (1024 * 1024); // Bytes a MB
    }
}
