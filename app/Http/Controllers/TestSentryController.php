<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;

/**
 * TestSentryController
 *
 * Controlador para probar el envío de errores a Sentry.
 * Solo disponible cuando FEATURE_DEBUG_ENDPOINTS está activado.
 *
 * @package App\Http\Controllers
 */
class TestSentryController extends Controller
{
    /**
     * Probar envío de diferentes niveles de log a Sentry
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testLogs(Request $request)
    {
        $level = $request->input('level', 'error');
        $message = $request->input('message', 'Test message from Sentry test endpoint');

        $results = [];

        // Probar LogService (envía a archivo y Sentry según nivel)
        try {
            LogService::{$level}($message, [
                'test' => true,
                'level' => $level,
                'timestamp' => now()->toIso8601String(),
            ]);
            $results['log_service'] = 'sent';
        } catch (\Exception $e) {
            $results['log_service'] = 'error: ' . $e->getMessage();
        }

        // Probar LogService (que usa captura directa de Sentry)
        try {
            LogService::{$level}($message, [
                'test' => true,
                'level' => $level,
                'timestamp' => now()->toIso8601String(),
            ]);
            $results['log_service_sentry'] = 'sent';
        } catch (\Exception $e) {
            $results['log_service_sentry'] = 'error: ' . $e->getMessage();
        }

        // Probar captura directa de Sentry
        if (class_exists(\Sentry\SentrySdk::class)) {
            try {
                $severityMap = [
                    'debug' => Severity::debug(),
                    'info' => Severity::info(),
                    'warning' => Severity::warning(),
                    'error' => Severity::error(),
                    'critical' => Severity::fatal(),
                ];
                
                $severity = $severityMap[$level] ?? Severity::error();
                \Sentry\captureMessage(
                    "Sentry Direct: {$message}",
                    $severity
                );
                $results['sentry_direct'] = 'sent';
            } catch (\Exception $e) {
                $results['sentry_direct'] = 'error: ' . $e->getMessage();
            }
        } else {
            $results['sentry_direct'] = 'Sentry SDK not available';
        }

        return ApiResponse::success([
            'level' => $level,
            'message' => $message,
            'environment' => config('app.env'),
            'sentry_configured' => !empty(config('sentry.dsn')),
            'sentry_level' => config('logging.channels.sentry.level'),
            'results' => $results,
            'note' => 'Check Sentry dashboard to verify if the message was received',
        ], 'Test logs sent to Sentry');
    }

    /**
     * Probar captura de excepción
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testException(Request $request)
    {
        $exceptionType = $request->input('type', 'generic');

        try {
            match ($exceptionType) {
                'generic' => throw new \Exception('Test generic exception for Sentry'),
                'runtime' => throw new \RuntimeException('Test runtime exception for Sentry'),
                'invalid_argument' => throw new \InvalidArgumentException('Test invalid argument exception for Sentry'),
                'custom' => throw new \App\Exceptions\ApiException('Test custom API exception', 500),
                default => throw new \Exception('Unknown exception type'),
            };
        } catch (\Exception $e) {
            // Capturar excepción en Sentry
            if (class_exists(\Sentry\SentrySdk::class)) {
                \Sentry\captureException($e);
            }

            // También usar LogService
            LogService::error('Test exception captured', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ApiResponse::success([
                'exception_type' => $exceptionType,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'environment' => config('app.env'),
                'sentry_configured' => !empty(config('sentry.dsn')),
                'note' => 'Exception was captured and sent to Sentry. Check dashboard.',
            ], 'Test exception sent to Sentry');
        }
    }

    /**
     * Información sobre configuración de Sentry
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function info()
    {
        return ApiResponse::success([
            'sentry_configured' => !empty(config('sentry.dsn')),
            'sentry_dsn' => config('sentry.dsn') ? '***configured***' : null,
            'sentry_environment' => config('sentry.environment') ?? config('app.env'),
            'sentry_release' => config('sentry.release'),
            'app_env' => config('app.env'),
            'sentry_log_level' => config('logging.channels.sentry.level'),
            'log_level' => config('logging.channels.single.level'),
            'available_levels' => ['debug', 'info', 'warning', 'error', 'critical'],
            'note' => 'In dev environment, only "critical" level is sent to Sentry',
        ], 'Sentry configuration information');
    }
}
