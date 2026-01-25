<?php

use App\Exceptions\ApiException;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\ExternalServiceException;
use App\Helpers\ApiResponse;
use App\Services\LogService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // No cargar rutas web (solo API)
        // web: __DIR__.'/../routes/web.php',
        // Cargar rutas API sin prefijo /api (directo en la raíz)
        using: function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware para API: todas las respuestas en JSON
        $middleware->api(prepend: [
            \App\Http\Middleware\CorsMiddleware::class, // CORS personalizado con ALLOWED_ORIGINS
            \App\Http\Middleware\TraceIdMiddleware::class, // Generar e inyectar Trace ID
            \App\Http\Middleware\AdaptiveRateLimitingMiddleware::class, // Rate limiting adaptativo por tipo de endpoint
            \App\Http\Middleware\SanitizeInput::class, // Limpieza de inputs (XSS, HTML, etc.)
            \App\Http\Middleware\TransformRequestMiddleware::class, // Normalización de requests
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\TransformResponseMiddleware::class, // Transformación de respuestas
            \App\Http\Middleware\LogApiRequests::class, // Registrar requests/responses
            \App\Http\Middleware\SecurityLoggerMiddleware::class, // Registrar eventos de seguridad y detectar patrones anómalos
            \App\Http\Middleware\RateLimitLoggerMiddleware::class, // Registrar bloqueos por rate limiting y detectar abuso
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Integración automática de Sentry para captura de excepciones
        // Esto captura automáticamente todas las excepciones y las envía a Sentry
        Integration::handles($exceptions);

        // Forzar respuestas JSON para todas las excepciones (API-only)
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return true; // Siempre devolver JSON
        });

        // Establecer tiempo de inicio para ApiResponse
        $exceptions->render(function (\Throwable $e, $request) {
            ApiResponse::setRequestStartTime();

            // Helper para logging de excepciones
            $logException = function (\Throwable $exception, $req, bool $isCritical = false): void {
                try {
                    $context = [
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'url' => $req->fullUrl(),
                        'method' => $req->method(),
                        'ip' => $req->ip(),
                        'user_agent' => $req->userAgent(),
                    ];

                    // Agregar usuario si está autenticado
                    if (auth()->check()) {
                        $context['user_id'] = auth()->id();
                    }

                    // Agregar trace_id si existe
                    if ($traceId = $req->header('X-Trace-ID') ?? $req->header('X-Request-ID')) {
                        $context['trace_id'] = $traceId;
                    }

                    // Log según severidad
                    if ($isCritical) {
                        LogService::logError($exception, $context);
                    } else {
                        LogService::warning("Exception: {$exception->getMessage()}", $context);
                    }
                } catch (\Exception $logError) {
                    // Silenciar errores de logging para no interrumpir el flujo principal
                }
            };

            // Helper para obtener tipo de error según código HTTP
            $getErrorType = function (int $statusCode): string {
                return match ($statusCode) {
                    400 => 'bad_request',
                    401 => 'unauthorized',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    409 => 'conflict',
                    422 => 'validation_error',
                    429 => 'rate_limit_exceeded',
                    500 => 'internal_server_error',
                    502 => 'bad_gateway',
                    503 => 'service_unavailable',
                    504 => 'gateway_timeout',
                    default => 'http_error',
                };
            };

            // ============================================
            // Excepciones Personalizadas
            // ============================================

            // ApiException (con RFC 7807)
            if ($e instanceof ApiException) {
                $logException($e, $request, $e->getStatusCode() >= 500);
                
                return ApiResponse::rfc7807(
                    $e->getMessage(),
                    $e->getStatusCode(),
                    $e->getMessage(),
                    $e->getType() ?? 'about:blank',
                    $e->getInstance() ?? $request->path(),
                    array_merge(
                        $e->getExtensions(),
                        !empty($e->getErrors()) ? ['errors' => $e->getErrors()] : []
                    )
                );
            }

            // BusinessLogicException
            if ($e instanceof BusinessLogicException) {
                $logException($e, $request, false);
                
                return ApiResponse::rfc7807(
                    $e->getMessage(),
                    422,
                    $e->getMessage(),
                    'business_logic_error',
                    $request->path(),
                    $e->getExtensions()
                );
            }

            // ExternalServiceException
            if ($e instanceof ExternalServiceException) {
                $isCritical = $e->getStatusCode() >= 500;
                $logException($e, $request, $isCritical);
                
                return ApiResponse::rfc7807(
                    $e->getMessage(),
                    $e->getStatusCode(),
                    $e->getMessage(),
                    'external_service_error',
                    $request->path(),
                    $e->getExtensions()
                );
            }

            // ============================================
            // Excepciones de Laravel/Symfony
            // ============================================

            // 404 - Ruta no encontrada
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $logException($e, $request, false);
                
                return ApiResponse::rfc7807(
                    'Endpoint not found.',
                    404,
                    'The requested endpoint does not exist.',
                    'not_found',
                    $request->path(),
                    ['path' => $request->path()]
                );
            }

            // ValidationException (422)
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $logException($e, $request, false);
                
                return ApiResponse::validation($e->errors(), 'The given data was invalid.');
            }

            // HttpException genéricas
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                $statusCode = $e->getStatusCode();
                $isCritical = $statusCode >= 500;
                $logException($e, $request, $isCritical);
                
                return ApiResponse::rfc7807(
                    $e->getMessage() ?: 'An error occurred',
                    $statusCode,
                    $e->getMessage() ?: 'An error occurred',
                    $getErrorType($statusCode),
                    $request->path()
                );
            }

            // AuthenticationException (401)
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $logException($e, $request, false);
                
                return ApiResponse::unauthorized('No autenticado');
            }

            // AuthorizationException (403)
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $logException($e, $request, false);
                
                return ApiResponse::forbidden('No autorizado');
            }

            // ============================================
            // Errores Críticos (500+)
            // ============================================

            // Logging automático para errores críticos
            $logException($e, $request, true);
            
            // Respuesta RFC 7807 para errores críticos
            $message = config('app.debug') 
                ? $e->getMessage() 
                : 'An internal server error occurred.';

            return ApiResponse::rfc7807(
                $message,
                500,
                config('app.debug') ? $e->getMessage() : null,
                'internal_server_error',
                $request->path(),
                config('app.debug') ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => collect($e->getTrace())->map(function ($trace) {
                        return Arr::except($trace, ['args']);
                    })->all(),
                ] : []
            );
        });
    })->create();
