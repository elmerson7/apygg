<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Services\Logging\ApiProblemLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiErrorStatsController extends Controller
{
    /**
     * Get general error statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 7);
        $days = min(30, max(1, $days)); // Límite entre 1 y 30 días
        
        $stats = ApiProblemLogger::getErrorStats($days);
        
        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'total_errors' => $stats['total_errors'],
                'daily_average' => round($stats['total_errors'] / $days, 2),
                'by_status' => $stats['by_status'],
                'by_type' => $stats['by_type'],
                'top_endpoints' => $stats['top_endpoints'],
                'error_rate_by_day' => $stats['error_rate_by_day'],
            ],
            'meta' => [
                'generated_at' => now()->toISOString(),
                'period' => [
                    'from' => now()->subDays($days)->toDateString(),
                    'to' => now()->toDateString(),
                ],
            ],
        ]);
    }

    /**
     * Get problematic endpoints (server errors).
     */
    public function problematicEndpoints(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 7);
        $limit = (int) $request->get('limit', 10);
        
        $days = min(30, max(1, $days));
        $limit = min(50, max(1, $limit));
        
        $endpoints = ApiProblemLogger::getProblematicEndpoints($days, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $endpoints,
            'meta' => [
                'period_days' => $days,
                'limit' => $limit,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get error trends by hour.
     */
    public function trends(Request $request): JsonResponse
    {
        $hours = (int) $request->get('hours', 24);
        $hours = min(168, max(1, $hours)); // Máximo 7 días (168 horas)
        
        $trends = ApiProblemLogger::getErrorTrends($hours);
        
        return response()->json([
            'success' => true,
            'data' => [
                'client_errors' => $trends['client_errors'],
                'server_errors' => $trends['server_errors'],
                'summary' => [
                    'total_client_errors' => array_sum(array_column($trends['client_errors'], 'count')),
                    'total_server_errors' => array_sum(array_column($trends['server_errors'], 'count')),
                    'hourly_avg_client' => round(array_sum(array_column($trends['client_errors'], 'count')) / $hours, 2),
                    'hourly_avg_server' => round(array_sum(array_column($trends['server_errors'], 'count')) / $hours, 2),
                ],
            ],
            'meta' => [
                'period_hours' => $hours,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get errors for a specific user.
     */
    public function userErrors(Request $request, string $userId): JsonResponse
    {
        $hours = (int) $request->get('hours', 24);
        $hours = min(168, max(1, $hours));
        
        $errors = ApiProblemLogger::getUserErrors($userId, $hours);
        
        return response()->json([
            'success' => true,
            'data' => $errors->map(function ($error) {
                return [
                    'id' => $error->id,
                    'type' => $error->type,
                    'title' => $error->title,
                    'status' => $error->status,
                    'detail' => $error->detail,
                    'instance' => $error->instance,
                    'created_at' => $error->created_at->toISOString(),
                    'context' => $error->context,
                ];
            }),
            'meta' => [
                'user_id' => $userId,
                'period_hours' => $hours,
                'total_errors' => $errors->count(),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get errors by trace ID for debugging.
     */
    public function traceErrors(Request $request, string $traceId): JsonResponse
    {
        $errors = ApiProblemLogger::getErrorsByTraceId($traceId);
        
        return response()->json([
            'success' => true,
            'data' => $errors->map(function ($error) {
                return [
                    'id' => $error->id,
                    'type' => $error->type,
                    'title' => $error->title,
                    'status' => $error->status,
                    'detail' => $error->detail,
                    'instance' => $error->instance,
                    'user_id' => $error->user_id,
                    'ip' => $error->ip,
                    'created_at' => $error->created_at->toISOString(),
                    'context' => $error->context,
                ];
            }),
            'meta' => [
                'trace_id' => $traceId,
                'total_errors' => $errors->count(),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get health check of API errors.
     */
    public function health(): JsonResponse
    {
        $lastHour = ApiProblemLogger::getErrorTrends(1);
        $last24Hours = ApiProblemLogger::getErrorTrends(24);
        
        $clientErrorsLastHour = array_sum(array_column($lastHour['client_errors'], 'count'));
        $serverErrorsLastHour = array_sum(array_column($lastHour['server_errors'], 'count'));
        $serverErrors24h = array_sum(array_column($last24Hours['server_errors'], 'count'));
        
        // Determinar estado de salud
        $health = 'healthy';
        if ($serverErrorsLastHour > 10) {
            $health = 'critical';
        } elseif ($serverErrorsLastHour > 5 || $serverErrors24h > 100) {
            $health = 'warning';
        } elseif ($clientErrorsLastHour > 50) {
            $health = 'degraded';
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'health_status' => $health,
                'last_hour' => [
                    'client_errors' => $clientErrorsLastHour,
                    'server_errors' => $serverErrorsLastHour,
                ],
                'last_24_hours' => [
                    'server_errors' => $serverErrors24h,
                ],
                'thresholds' => [
                    'server_errors_hour_warning' => 5,
                    'server_errors_hour_critical' => 10,
                    'server_errors_24h_warning' => 100,
                    'client_errors_hour_degraded' => 50,
                ],
            ],
            'meta' => [
                'checked_at' => now()->toISOString(),
            ],
        ]);
    }
}
