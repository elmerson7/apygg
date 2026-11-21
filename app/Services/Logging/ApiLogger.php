<?php

namespace App\Services\Logging;

use App\Helpers\Actor;
use App\Helpers\Redactor;
use App\Models\Logs\ApiLog;
use App\Services\LogService;
use App\Support\ApiPath;
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
    protected const EXCLUDED_PATHS = [
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
    protected const EXCLUDED_HEADERS = [
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
            if (self::shouldExcludePath(ApiPath::path($request))) {
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
            $userId = Actor::id();

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
        foreach ([...self::EXCLUDED_PATHS, ...(array) config('logging.api.excluded_paths', [])] as $excludedPath) {
            if (Str::contains($normalizedPath, $excludedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitizar query parameters (eliminar sensibles)
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected static function sanitizeQuery(array $query): array
    {
        return Redactor::redact($query);
    }

    /**
     * Sanitizar body (eliminar campos sensibles, también anidados)
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected static function sanitizeBody(?array $body): ?array
    {
        return $body === null ? null : Redactor::redact($body);
    }

    /**
     * Sanitizar headers (eliminar sensibles)
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    protected static function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (Redactor::isSensitiveKey($lowerKey) || in_array($lowerKey, [...self::EXCLUDED_HEADERS, ...array_map('strtolower', (array) config('logging.api.excluded_headers', []))])) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = Redactor::redact(is_array($value) ? $value[0] ?? $value : $value);
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
}
