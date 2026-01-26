<?php

namespace App\Services\Logging;

use App\Models\Logs\ApiLog;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ApiLogger
 *
 * Logger especializado para registrar todos los requests y responses de la API.
 * Se usa típicamente en un middleware para captura automática.
 */
class ApiLogger
{
    /**
     * Rutas que deben ser excluidas del logging
     *
     * @var array<string>
     */
    protected static array $excludedPaths = [
        'health',
        'ping',
        'telescope',
        'horizon',
    ];

    /**
     * Headers que deben ser excluidos del logging (sensibles)
     *
     * @var array<string>
     */
    protected static array $excludedHeaders = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-auth-token',
    ];

    /**
     * Registrar un request y response de la API
     *
     * @param  float|null  $responseTime  Tiempo de respuesta en milisegundos
     * @param  string|null  $traceId  Trace ID del request (si no se proporciona, se genera uno)
     */
    public static function logRequest(
        Request $request,
        Response|SymfonyResponse $response,
        ?float $responseTime = null,
        ?string $traceId = null
    ): ?ApiLog {
        try {
            // Verificar si la ruta debe ser excluida
            if (self::shouldExcludePath($request->path())) {
                return null;
            }

            // Obtener trace ID del header o generar uno nuevo
            // IMPORTANTE: Cada request debe tener su propio trace_id único
            // Si viene del header, usarlo; si no, generar uno único para este log
            $traceId = $traceId ?? $request->header('X-Trace-ID');

            // Si no hay trace_id, generar uno nuevo (único por request)
            if (! $traceId) {
                $traceId = (string) Str::uuid();
            }

            // Obtener usuario autenticado
            $userId = auth()->id();

            // Preparar datos del request
            $requestData = [
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => self::sanitizeQuery($request->query()),
                'body' => self::sanitizeBody($request->all()),
                'headers' => self::sanitizeHeaders($request->headers->all()),
            ];

            // Preparar datos de la respuesta
            $responseData = [
                'status' => $response->getStatusCode(),
                'headers' => self::sanitizeHeaders($response->headers->all()),
            ];

            // Intentar obtener el body de la respuesta (si es JSON)
            if ($response instanceof Response) {
                $content = $response->getContent();
                if (is_string($content) && $content !== '') {
                    $json = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $responseData['body'] = self::sanitizeBody($json);
                    }
                }
            }

            // Calcular tiempo de respuesta si no se proporciona
            if ($responseTime === null) {
                $responseTime = self::calculateResponseTime($request);
            }

            return ApiLog::create([
                'trace_id' => $traceId,
                'user_id' => $userId,
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'request_query' => $requestData['query'],
                'request_body' => $requestData['body'],
                'request_headers' => $requestData['headers'],
                'response_status' => $response->getStatusCode(),
                'response_body' => $responseData['body'] ?? null,
                'response_time_ms' => (int) round($responseTime),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
            ]);
        } catch (\Exception $e) {
            // Log del error pero no interrumpir el flujo principal
            LogService::error('Failed to log API request', [
                'path' => $request->path(),
                'method' => $request->method(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verificar si una ruta debe ser excluida del logging
     */
    protected static function shouldExcludePath(string $path): bool
    {
        // Normalizar path (remover slash inicial si existe)
        $normalizedPath = ltrim($path, '/');

        // Excluir ruta raíz explícitamente
        if ($path === '/' || $normalizedPath === '') {
            return true;
        }

        // Verificar si el path contiene alguno de los paths excluidos
        foreach (self::$excludedPaths as $excludedPath) {
            if (Str::contains($normalizedPath, $excludedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitizar query parameters (eliminar sensibles)
     */
    protected static function sanitizeQuery(array $query): array
    {
        $sensitiveKeys = ['password', 'token', 'key', 'secret', 'api_key'];
        $sanitized = [];

        foreach ($query as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizar body (eliminar campos sensibles)
     */
    protected static function sanitizeBody(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'api_token', 'secret', 'key'];
        $sanitized = [];

        foreach ($body as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeBody($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizar headers (eliminar sensibles)
     */
    protected static function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, self::$excludedHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_array($value) ? $value[0] ?? $value : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Calcular tiempo de respuesta desde el inicio del request
     *
     * @return float Tiempo en milisegundos
     */
    protected static function calculateResponseTime(Request $request): float
    {
        $startTime = $request->server('REQUEST_TIME_FLOAT');

        if ($startTime) {
            return (microtime(true) - $startTime) * 1000;
        }

        return 0;
    }

    /**
     * Agregar rutas a la lista de excluidas
     *
     * @param  array<string>  $paths
     */
    public static function excludePaths(array $paths): void
    {
        self::$excludedPaths = array_merge(self::$excludedPaths, $paths);
    }

    /**
     * Agregar headers a la lista de excluidos
     *
     * @param  array<string>  $headers
     */
    public static function excludeHeaders(array $headers): void
    {
        self::$excludedHeaders = array_merge(self::$excludedHeaders, $headers);
    }
}
