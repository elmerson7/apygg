<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;
use Meilisearch\Client;

class HealthController extends Controller
{
    /**
     * Liveness probe — solo verifica que la app responde
     */
    #[Response(200, 'La aplicación responde', type: 'array{status: "alive", timestamp: string}')]
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness probe — verifica servicios críticos: DB y Redis
     */
    #[Response(200, 'DB y Redis responden', type: 'array{status: "ready", timestamp: string, services: array<string, array{status: string, latency_ms?: float, message?: string}>}')]
    #[Response(503, 'Algún servicio crítico no responde', type: 'array{status: "unhealthy", timestamp: string, services: array<string, array{status: string, latency_ms?: float, message?: string}>}')]
    public function ready(): JsonResponse
    {
        $services = [];
        $overallStatus = 'ready';
        $statusCode = 200;

        // Database
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $services['database'] = ['status' => 'ok', 'latency_ms' => round((microtime(true) - $startTime) * 1000, 2)];
        } catch (\Exception $e) {
            $services['database'] = ['status' => 'error', 'message' => 'Connection failed'];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Redis
        try {
            $startTime = microtime(true);
            Redis::connection()->ping();
            $services['redis'] = ['status' => 'ok', 'latency_ms' => round((microtime(true) - $startTime) * 1000, 2)];
        } catch (\Exception $e) {
            $services['redis'] = ['status' => 'error', 'message' => 'Connection failed'];
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
     * Alias de ready() para compatibilidad con /health
     */
    #[Response(200, 'DB y Redis responden', type: 'array{status: "ready", timestamp: string, services: array<string, array{status: string, latency_ms?: float, message?: string}>}')]
    #[Response(503, 'Algún servicio crítico no responde', type: 'array{status: "unhealthy", timestamp: string, services: array<string, array{status: string, latency_ms?: float, message?: string}>}')]
    public function check(Request $request): JsonResponse
    {
        return $this->ready();
    }

    /**
     * Health check detallado (requiere auth:api)
     * Verifica DB, Redis, Meilisearch y Horizon
     */
    #[Response(200, 'Servicios sanos o degradados (Meilisearch/Horizon opcionales)', type: 'array{status: "healthy"|"degraded", version: string, timestamp: string, environment: string, services: array<string, array{status: string, latency_ms?: float, message?: string, connection?: string, read_write?: bool, status_from_api?: string, workers_active?: int}>}')]
    #[Response(503, 'DB o Redis no responden', type: 'array{status: "unhealthy", version: string, timestamp: string, environment: string, services: array<string, array{status: string, latency_ms?: float, message?: string, connection?: string, read_write?: bool, status_from_api?: string, workers_active?: int}>}')]
    public function detailed(): JsonResponse
    {
        $services = [];
        $overallStatus = 'healthy';
        $hasDegraded = false;
        $statusCode = 200;

        // Database
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $services['database'] = [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'connection' => DB::connection()->getName(),
            ];
        } catch (\Exception $e) {
            $services['database'] = ['status' => 'error', 'message' => 'Connection failed'];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Redis
        try {
            $startTime = microtime(true);
            Redis::connection()->ping();
            $testKey = 'health_check_'.time();
            Cache::put($testKey, 'test', 1);
            $value = Cache::get($testKey);
            Cache::forget($testKey);
            $services['redis'] = [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'read_write' => $value === 'test',
            ];
        } catch (\Exception $e) {
            $services['redis'] = ['status' => 'error', 'message' => 'Connection failed'];
            $overallStatus = 'unhealthy';
            $statusCode = 503;
        }

        // Meilisearch (opcional — FQCN inline para evitar error fatal si el paquete no está)
        if (config('scout.driver') === 'meilisearch' && config('scout.meilisearch.host')) {
            try {
                $startTime = microtime(true);
                if (class_exists(Client::class)) {
                    $client = app(Client::class);
                    $health = $client->health();
                    $services['meilisearch'] = [
                        'status' => 'ok',
                        'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        'status_from_api' => $health['status'] ?? 'unknown',
                    ];
                } else {
                    $services['meilisearch'] = ['status' => 'not_configured', 'message' => 'Meilisearch client not available'];
                    $hasDegraded = true;
                }
            } catch (\Exception $e) {
                $services['meilisearch'] = ['status' => 'error', 'message' => 'Connection failed'];
                $hasDegraded = true;
            }
        }

        // Horizon (opcional — FQCN inline)
        if (class_exists(Horizon::class)) {
            try {
                $startTime = microtime(true);
                if (method_exists(Horizon::class, 'status')) {
                    $horizonStatus = Horizon::status();
                    $services['horizon'] = [
                        'status' => 'ok',
                        'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        'workers_active' => $horizonStatus['processes'] ?? 0,
                    ];
                } else {
                    $services['horizon'] = ['status' => 'not_configured', 'message' => 'Horizon status method not available'];
                    $hasDegraded = true;
                }
            } catch (\Exception $e) {
                $services['horizon'] = ['status' => 'error', 'message' => 'Connection failed'];
                $hasDegraded = true;
            }
        }

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
