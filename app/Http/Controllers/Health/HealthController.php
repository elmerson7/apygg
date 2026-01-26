<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    /**
     * Health check básico - Liveness probe
     * Verifica solo que la aplicación está viva y respondiendo
     * Sin verificación de servicios externos
     *
     * @return JsonResponse
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }

    /**
     * Health check básico - Readiness probe
     * Verifica servicios críticos: Database y Redis
     *
     * @return JsonResponse
     */
    public function ready(): JsonResponse
    {
        $services = [];
        $overallStatus = 'ready';
        $statusCode = 200;

        // Verificar Database
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $services['database'] = [
                'status' => 'ok',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            $services['database'] = [
                'status' => 'error',
                'message' => 'Connection failed',
            ];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Verificar Redis
        try {
            $startTime = microtime(true);
            Redis::connection()->ping();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $services['redis'] = [
                'status' => 'ok',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            $services['redis'] = [
                'status' => 'error',
                'message' => 'Connection failed',
            ];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        return response()->json([
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
        ], $statusCode);
    }

    /**
     * Health check básico (alias de ready para compatibilidad)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function check(\Illuminate\Http\Request $request): JsonResponse
    {
        return $this->ready();
    }

    /**
     * Health check detallado con autenticación requerida
     * Verifica todos los servicios incluyendo opcionales
     *
     * @return JsonResponse
     */
    public function detailed(): JsonResponse
    {
        $services = [];
        $overallStatus = 'healthy';
        $hasDegraded = false;
        $statusCode = 200;

        // Verificar Database
        try {
            $startTime = microtime(true);
            $pdo = DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            // Verificar que puede ejecutar una query simple
            DB::select('SELECT 1');

            $services['database'] = [
                'status' => 'ok',
                'latency_ms' => $latency,
                'connection' => DB::connection()->getName(),
            ];
        } catch (\Exception $e) {
            $services['database'] = [
                'status' => 'error',
                'message' => 'Connection failed',
            ];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Verificar Redis
        try {
            $startTime = microtime(true);
            Redis::connection()->ping();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            // Verificar que puede escribir y leer
            $testKey = 'health_check_' . time();
            Cache::put($testKey, 'test', 1);
            $value = Cache::get($testKey);
            Cache::forget($testKey);

            $services['redis'] = [
                'status' => 'ok',
                'latency_ms' => $latency,
                'read_write' => $value === 'test',
            ];
        } catch (\Exception $e) {
            $services['redis'] = [
                'status' => 'error',
                'message' => 'Connection failed',
            ];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Verificar Meilisearch (opcional)
        if (config('scout.driver') === 'meilisearch' && config('scout.meilisearch.host')) {
            try {
                $startTime = microtime(true);
                // Verificar si la clase existe antes de usarla
                if (class_exists(\Meilisearch\Client::class)) {
                    $client = app(\Meilisearch\Client::class);
                    $health = $client->health();
                    $latency = round((microtime(true) - $startTime) * 1000, 2);

                    $services['meilisearch'] = [
                        'status' => 'ok',
                        'latency_ms' => $latency,
                        'status_from_api' => $health['status'] ?? 'unknown',
                    ];
                } else {
                    $services['meilisearch'] = [
                        'status' => 'not_configured',
                        'message' => 'Meilisearch client not available',
                    ];
                    $hasDegraded = true;
                }
            } catch (\Exception $e) {
                $services['meilisearch'] = [
                    'status' => 'error',
                    'message' => 'Connection failed',
                ];
                $hasDegraded = true;
            }
        }

        // Verificar Horizon (opcional)
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            try {
                $startTime = microtime(true);
                // Verificar si Horizon está disponible y tiene el método status
                if (method_exists(\Laravel\Horizon\Horizon::class, 'status')) {
                    $horizonStatus = \Laravel\Horizon\Horizon::status();
                    $latency = round((microtime(true) - $startTime) * 1000, 2);

                    $services['horizon'] = [
                        'status' => 'ok',
                        'latency_ms' => $latency,
                        'workers_active' => $horizonStatus['processes'] ?? 0,
                    ];
                } else {
                    $services['horizon'] = [
                        'status' => 'not_configured',
                        'message' => 'Horizon status method not available',
                    ];
                    $hasDegraded = true;
                }
            } catch (\Exception $e) {
                $services['horizon'] = [
                    'status' => 'error',
                    'message' => 'Connection failed',
                ];
                $hasDegraded = true;
            }
        }

        // Si hay servicios degradados pero los críticos están OK
        if ($hasDegraded && $overallStatus === 'healthy') {
            $overallStatus = 'degraded';
        }

        return response()->json([
            'status' => $overallStatus,
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'services' => $services,
        ], $statusCode);
    }
}
