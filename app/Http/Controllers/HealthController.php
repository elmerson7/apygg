<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(
        private HealthCheckService $healthCheckService
    ) {}

    /**
     * Comprehensive health check endpoint.
     * 
     * Verifica el estado de todos los servicios críticos:
     * - Bases de datos (principal y logs)
     * - Redis (cache y colas)
     * - Meilisearch (búsquedas)
     * - Storage/S3 (archivos)
     * - Horizon (procesamiento de colas)
     * - Reverb (WebSockets)
     */
    public function health(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        
        $checks = $this->healthCheckService->runAllChecks();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        $response = [
            'status' => $overallStatus,
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
            'response_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ];

        // Retornar código HTTP apropiado según el estado
        $statusCode = match($overallStatus) {
            'healthy' => 200,
            'degraded' => 200, // Parcialmente funcional
            'unhealthy' => 503  // Service Unavailable
        };

        return response()->apiJson($response, $statusCode);
    }

    /**
     * Endpoint básico compatible con /up para load balancers.
     */
    public function up(): JsonResponse
    {
        return response()->apiJson([
            'status' => 'up',
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Determina el estado general basado en los checks individuales.
     */
    private function determineOverallStatus(array $checks): string
    {
        $healthyCount = 0;
        $totalCount = count($checks);
        $criticalServices = ['database', 'redis']; // Servicios críticos

        foreach ($checks as $service => $check) {
            if ($check['status'] === 'healthy') {
                $healthyCount++;
            } elseif (in_array($service, $criticalServices) && $check['status'] === 'unhealthy') {
                // Si un servicio crítico falla, toda la app está unhealthy
                return 'unhealthy';
            }
        }

        // Si todos están healthy
        if ($healthyCount === $totalCount) {
            return 'healthy';
        }

        // Si la mayoría están healthy y los críticos funcionan
        if ($healthyCount >= ($totalCount * 0.7)) {
            return 'degraded';
        }

        return 'unhealthy';
    }
}
