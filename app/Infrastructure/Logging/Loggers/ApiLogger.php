<?php

namespace App\Infrastructure\Logging\Loggers;

use App\Infrastructure\Logging\Models\ApiLog;
use App\Infrastructure\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ApiLogger
 *
 * Logger especializado para registrar todos los requests y responses de la API.
 * Se usa típicamente en un middleware para captura automática.
 *
 * @package App\Infrastructure\Logging\Loggers
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
     * @param Request $request
     * @param Response|SymfonyResponse $response
     * @param float|null $responseTime Tiempo de respuesta en milisegundos
     * @param string|null $traceId Trace ID del request (si no se proporciona, se genera uno)
     * @return ApiLog|null
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

            // Obtener trace ID
            $traceId = $traceId ?? LogService::getTraceId();

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
                if ($content && is_string($content)) {
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
     *
     * @param string $path
     * @return bool
     */
    protected static function shouldExcludePath(string $path): bool
    {
        foreach (self::$excludedPaths as $excludedPath) {
            if (Str::contains($path, $excludedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitizar query parameters (eliminar sensibles)
     *
     * @param array $query
     * @return array
     */
    protected static function sanitizeQuery(array $query): array
    {
        $sensitiveKeys = ['password', 'token', 'key', 'secret', 'api_key'];

        return array_map(function ($value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                return '[REDACTED]';
            }
            return $value;
        }, $query, array_keys($query));
    }

    /**
     * Sanitizar body (eliminar campos sensibles)
     *
     * @param array|null $body
     * @return array|null
     */
    protected static function sanitizeBody(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'api_token', 'secret', 'key'];

        return array_map(function ($value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                return '[REDACTED]';
            }
            if (is_array($value)) {
                return self::sanitizeBody($value);
            }
            return $value;
        }, $body, array_keys($body));
    }

    /**
     * Sanitizar headers (eliminar sensibles)
     *
     * @param array $headers
     * @return array
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
     * @param Request $request
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
     * @param array<string> $paths
     * @return void
     */
    public static function excludePaths(array $paths): void
    {
        self::$excludedPaths = array_merge(self::$excludedPaths, $paths);
    }

    /**
     * Agregar headers a la lista de excluidos
     *
     * @param array<string> $headers
     * @return void
     */
    public static function excludeHeaders(array $headers): void
    {
        self::$excludedHeaders = array_merge(self::$excludedHeaders, $headers);
    }
}
