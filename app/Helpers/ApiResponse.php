<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * ApiResponse
 * 
 * Helper estático para generar respuestas API estándar con formato consistente.
 * Soporta RFC 7807 para errores y metadatos enriquecidos.
 * 
 * @package App\Helpers
 */
class ApiResponse
{
    /**
     * Versión de la API
     */
    private static string $apiVersion = '1.0';

    /**
     * Tiempo de inicio de la request (para calcular execution_time_ms)
     */
    private static ?float $requestStartTime = null;

    /**
     * Establecer tiempo de inicio de la request
     */
    public static function setRequestStartTime(?float $startTime = null): void
    {
        self::$requestStartTime = $startTime ?? microtime(true);
    }

    /**
     * Obtener tiempo de ejecución en milisegundos
     */
    private static function getExecutionTime(): ?int
    {
        if (self::$requestStartTime === null) {
            return null;
        }

        return (int) ((microtime(true) - self::$requestStartTime) * 1000);
    }

    /**
     * Obtener trace ID de la request actual
     */
    private static function getTraceId(): ?string
    {
        return request()->header('X-Trace-ID') 
            ?? request()->header('X-Request-ID')
            ?? null;
    }

    /**
     * Generar metadatos estándar para respuestas
     */
    private static function getMeta(?string $requestId = null): array
    {
        $meta = [
            'version' => self::$apiVersion,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($traceId = self::getTraceId()) {
            $meta['request_id'] = $traceId;
        } elseif ($requestId) {
            $meta['request_id'] = $requestId;
        }

        if ($executionTime = self::getExecutionTime()) {
            $meta['execution_time_ms'] = $executionTime;
        }

        return $meta;
    }

    /**
     * Generar headers estándar para respuestas
     */
    private static function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($traceId = self::getTraceId()) {
            $headers['X-Trace-ID'] = $traceId;
        }

        return $headers;
    }

    /**
     * Respuesta exitosa estándar
     *
     * @param mixed $data Datos a retornar
     * @param string $message Mensaje de éxito
     * @param int $statusCode Código HTTP (default: 200)
     * @param array|null $links Links relacionados (HATEOAS)
     * @return JsonResponse
     */
    public static function success(
        $data = null,
        string $message = 'Operación exitosa',
        int $statusCode = 200,
        ?array $links = null
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'meta' => self::getMeta(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($links !== null) {
            $response['links'] = $links;
        }

        return response()->json($response, $statusCode, self::getHeaders());
    }

    /**
     * Respuesta de creación exitosa (201)
     *
     * @param mixed $data Datos del recurso creado
     * @param string $message Mensaje de éxito
     * @param string|null $location URL del recurso creado (para header Location)
     * @return JsonResponse
     */
    public static function created($data = null, string $message = 'Creado exitosamente', ?string $location = null): JsonResponse
    {
        $headers = self::getHeaders();
        
        if ($location) {
            $headers['Location'] = $location;
        }

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => self::getMeta(),
        ];

        return response()->json($response, 201, $headers);
    }

    /**
     * Respuesta paginada
     *
     * @param LengthAwarePaginator $paginator Instancia del paginador
     * @param string $message Mensaje de éxito
     * @return JsonResponse
     */
    public static function paginated(LengthAwarePaginator $paginator, string $message = 'Datos obtenidos exitosamente'): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => self::getMeta(),
        ];

        return response()->json($response, 200, self::getHeaders());
    }

    /**
     * Respuesta de error estándar
     *
     * @param string $message Mensaje de error
     * @param int $statusCode Código HTTP (default: 400)
     * @param array $errors Errores adicionales (opcional)
     * @param string|null $type Tipo de error (RFC 7807)
     * @return JsonResponse
     */
    public static function error(
        string $message = 'Error en la operación',
        int $statusCode = 400,
        array $errors = [],
        ?string $type = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
            'meta' => self::getMeta(),
        ];

        // Formato RFC 7807 para errores
        if ($type) {
            $response['type'] = $type;
        }

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode, self::getHeaders());
    }

    /**
     * Respuesta de error de validación (422)
     *
     * @param array $errors Errores de validación
     * @param string $message Mensaje de error
     * @return JsonResponse
     */
    public static function validation(array $errors, string $message = 'Error de validación'): JsonResponse
    {
        return self::error($message, 422, $errors, 'validation_error');
    }

    /**
     * Respuesta 404 - Recurso no encontrado
     *
     * @param string $message Mensaje de error
     * @return JsonResponse
     */
    public static function notFound(string $message = 'Recurso no encontrado'): JsonResponse
    {
        return self::error($message, 404, [], 'not_found');
    }

    /**
     * Respuesta 401 - No autenticado
     *
     * @param string $message Mensaje de error
     * @return JsonResponse
     */
    public static function unauthorized(string $message = 'No autenticado'): JsonResponse
    {
        return self::error($message, 401, [], 'unauthorized');
    }

    /**
     * Respuesta 403 - No autorizado
     *
     * @param string $message Mensaje de error
     * @return JsonResponse
     */
    public static function forbidden(string $message = 'No autorizado'): JsonResponse
    {
        return self::error($message, 403, [], 'forbidden');
    }

    /**
     * Respuesta 429 - Rate limit excedido
     *
     * @param string $message Mensaje de error
     * @param int|null $retryAfter Segundos hasta el próximo intento
     * @return JsonResponse
     */
    public static function rateLimited(string $message = 'Límite de requests excedido', ?int $retryAfter = null): JsonResponse
    {
        $headers = self::getHeaders();
        
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        $response = [
            'success' => false,
            'message' => $message,
            'type' => 'rate_limit_exceeded',
            'meta' => self::getMeta(),
        ];

        return response()->json($response, 429, $headers);
    }

    /**
     * Respuesta 500 - Error interno del servidor
     *
     * @param string $message Mensaje de error
     * @param bool $logError Si debe loguear el error
     * @return JsonResponse
     */
    public static function serverError(string $message = 'Error interno del servidor', bool $logError = true): JsonResponse
    {
        if ($logError) {
            Log::error('Server error response', [
                'message' => $message,
                'trace_id' => self::getTraceId(),
            ]);
        }

        return self::error($message, 500, [], 'server_error');
    }

    /**
     * Respuesta RFC 7807 completa para errores
     *
     * @param string $title Título del error
     * @param int $status Código HTTP
     * @param string|null $detail Detalle del error
     * @param string|null $type Tipo de error (URI)
     * @param string|null $instance Instancia específica del error
     * @param array $extensions Campos adicionales
     * @return JsonResponse
     */
    public static function rfc7807(
        string $title,
        int $status,
        ?string $detail = null,
        ?string $type = null,
        ?string $instance = null,
        array $extensions = []
    ): JsonResponse {
        $response = [
            'type' => $type ?? 'about:blank',
            'title' => $title,
            'status' => $status,
        ];

        if ($detail) {
            $response['detail'] = $detail;
        }

        if ($instance) {
            $response['instance'] = $instance;
        }

        if (!empty($extensions)) {
            $response = array_merge($response, $extensions);
        }

        $response['meta'] = self::getMeta();

        return response()->json($response, $status, self::getHeaders());
    }
}
