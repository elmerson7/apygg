<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Scout\EngineManager;
use Exception;
use Throwable;

class HealthCheckService
{
    /**
     * Ejecuta todos los health checks.
     */
    public function runAllChecks(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'database_logs' => $this->checkDatabaseLogs(),
            'redis' => $this->checkRedis(),
            'meilisearch' => $this->checkMeilisearch(),
            'storage' => $this->checkStorage(),
            'horizon' => $this->checkHorizon(),
            'reverb' => $this->checkReverb(),
        ];
    }

    /**
     * Verifica la conexión a la base de datos principal.
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test simple query
            DB::connection()->getPdo();
            $result = DB::select('SELECT 1 as test');
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if (!empty($result) && $result[0]->test == 1) {
                return [
                    'status' => 'healthy',
                    'response_time' => $responseTime . 'ms',
                    'connection' => config('database.default')
                ];
            }
            
            return [
                'status' => 'unhealthy',
                'error' => 'Query returned unexpected result',
                'response_time' => $responseTime . 'ms'
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica la conexión a la base de datos de logs.
     */
    private function checkDatabaseLogs(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test conexión logs
            DB::connection('logs')->getPdo();
            $result = DB::connection('logs')->select('SELECT 1 as test');
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if (!empty($result) && $result[0]->test == 1) {
                return [
                    'status' => 'healthy',
                    'response_time' => $responseTime . 'ms',
                    'connection' => 'logs'
                ];
            }
            
            return [
                'status' => 'unhealthy',
                'error' => 'Query returned unexpected result',
                'response_time' => $responseTime . 'ms'
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica la conexión a Redis.
     */
    private function checkRedis(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test ping
            $pong = Redis::ping();
            
            // Test set/get
            $testKey = 'health_check_' . time();
            Redis::setex($testKey, 5, 'test_value');
            $value = Redis::get($testKey);
            Redis::del($testKey);
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($pong && $value === 'test_value') {
                return [
                    'status' => 'healthy',
                    'response_time' => $responseTime . 'ms',
                    'ping' => $pong
                ];
            }
            
            return [
                'status' => 'unhealthy',
                'error' => 'Redis operations failed',
                'response_time' => $responseTime . 'ms'
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica la conexión a Meilisearch.
     */
    private function checkMeilisearch(): array
    {
        try {
            $startTime = microtime(true);
            
            // Verificar si Scout está configurado para Meilisearch
            if (config('scout.driver') !== 'meilisearch') {
                return [
                    'status' => 'degraded',
                    'message' => 'Scout not configured for Meilisearch',
                    'driver' => config('scout.driver')
                ];
            }

            // Test básico de conexión HTTP a Meilisearch
            $host = config('scout.meilisearch.host');
            $key = config('scout.meilisearch.key');
            
            if (!$host) {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Meilisearch host not configured'
                ];
            }

            // Hacer un request básico al endpoint de health de Meilisearch
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 3,
                    'header' => $key ? "Authorization: Bearer {$key}\r\n" : ''
                ]
            ]);

            $healthUrl = rtrim($host, '/') . '/health';
            $result = @file_get_contents($healthUrl, false, $context);
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result !== false) {
                $healthData = json_decode($result, true);
                
                return [
                    'status' => 'healthy',
                    'response_time' => $responseTime . 'ms',
                    'engine' => 'meilisearch',
                    'health' => $healthData['status'] ?? 'available'
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Could not connect to Meilisearch',
                    'response_time' => $responseTime . 'ms'
                ];
            }
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica el storage (S3 o local).
     */
    private function checkStorage(): array
    {
        try {
            $startTime = microtime(true);
            
            $disk = Storage::disk(config('filesystems.default'));
            $testFile = 'health_check_' . time() . '.txt';
            
            // Test write
            $disk->put($testFile, 'health check content');
            
            // Test read
            $content = $disk->get($testFile);
            
            // Test delete
            $disk->delete($testFile);
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($content === 'health check content') {
                return [
                    'status' => 'healthy',
                    'response_time' => $responseTime . 'ms',
                    'disk' => config('filesystems.default')
                ];
            }
            
            return [
                'status' => 'unhealthy',
                'error' => 'Storage operations failed',
                'response_time' => $responseTime . 'ms'
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica el estado de Horizon (colas).
     */
    private function checkHorizon(): array
    {
        try {
            if (!class_exists('\Laravel\Horizon\Horizon')) {
                return [
                    'status' => 'degraded',
                    'message' => 'Horizon not installed'
                ];
            }

            $startTime = microtime(true);
            
            // Verificar si Horizon está corriendo
            $masters = app(MasterSupervisorRepository::class)->all();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if (empty($masters)) {
                return [
                    'status' => 'unhealthy',
                    'error' => 'No Horizon supervisors running',
                    'response_time' => $responseTime . 'ms'
                ];
            }
            
            // Contar workers activos
            $totalWorkers = 0;
            foreach ($masters as $master) {
                $totalWorkers += count($master->supervisors);
            }
            
            return [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'supervisors' => count($masters),
                'workers' => $totalWorkers
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }

    /**
     * Verifica el estado de Reverb (WebSockets).
     */
    private function checkReverb(): array
    {
        try {
            $startTime = microtime(true);
            
            // Verificar si el broadcasting está configurado
            $broadcastDriver = config('broadcasting.default');
            
            if ($broadcastDriver !== 'reverb') {
                return [
                    'status' => 'degraded',
                    'message' => 'Broadcasting not using Reverb',
                    'driver' => $broadcastDriver
                ];
            }
            
            // Test básico de configuración
            $reverbConfig = config('broadcasting.connections.reverb');
            
            if (empty($reverbConfig)) {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Reverb configuration missing'
                ];
            }
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // En un escenario real, podrías hacer un ping al servidor Reverb
            // Por ahora verificamos solo la configuración
            return [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'driver' => 'reverb'
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => 'timeout'
            ];
        }
    }
}
