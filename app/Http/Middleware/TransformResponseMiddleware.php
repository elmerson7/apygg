<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * TransformResponseMiddleware
 *
 * Middleware para transformar y normalizar todas las respuestas
 * antes de enviarlas al cliente. Asegura formato consistente.
 */
class TransformResponseMiddleware
{
    /**
     * Rutas que NO deben ser transformadas
     */
    private array $excludedPaths = [
        '/up', // Health check
        '/telescope',
        '/horizon',
        '/', // Endpoint principal de la API (no transformar)
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // No transformar si la ruta está excluida
        if ($this->shouldExclude($request)) {
            return $response;
        }

        // Solo transformar respuestas JSON
        if ($response instanceof JsonResponse || $this->isJsonResponse($response)) {
            return $this->transformJsonResponse($response, $request);
        }

        return $response;
    }

    /**
     * Verificar si la ruta debe ser excluida
     */
    private function shouldExclude(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->excludedPaths as $excludedPath) {
            if (str_starts_with($path, ltrim($excludedPath, '/'))) {
                return true;
            }
        }

        return false;
    }


    /**
     * Verificar si la respuesta es JSON
     */
    private function isJsonResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');
        return str_contains($contentType, 'application/json');
    }

    /**
     * Transformar respuesta JSON
     */
    private function transformJsonResponse(Response $response, Request $request): Response
    {
        $content = $response->getContent();
        
        if (empty($content)) {
            return $response;
        }

        $data = json_decode($content, true);

        // Si no es JSON válido, retornar respuesta original
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $response;
        }


        // Si la respuesta ya tiene el formato estándar (success, data, meta), no transformar
        if (isset($data['success']) || isset($data['type'])) {
            return $response;
        }

        // Transformar respuesta
        $transformed = $this->transformData($data, $response->getStatusCode());

        // Crear nueva respuesta JSON
        return response()->json($transformed, $response->getStatusCode(), $this->getHeaders($response));
    }

    /**
     * Transformar datos según el código de estado
     */
    private function transformData(array $data, int $statusCode): array
    {
        // Respuestas exitosas (2xx)
        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->transformSuccessResponse($data, $statusCode);
        }

        // Respuestas de error (4xx, 5xx)
        if ($statusCode >= 400) {
            return $this->transformErrorResponse($data, $statusCode);
        }

        return $data;
    }

    /**
     * Transformar respuesta exitosa
     */
    private function transformSuccessResponse(array $data, int $statusCode): array
    {
        $response = [
            'success' => true,
            'message' => $this->getDefaultSuccessMessage($statusCode),
        ];

        // Si es una respuesta de creación (201), mensaje específico
        if ($statusCode === 201) {
            $response['message'] = 'Recurso creado exitosamente';
        }

        // Agregar datos
        if (!empty($data)) {
            // Si los datos ya tienen estructura de paginación, mantenerla
            if (isset($data['data']) && isset($data['pagination'])) {
                $response = array_merge($response, $data);
            } else {
                $response['data'] = $data;
            }
        }

        // Agregar metadatos
        $response['meta'] = $this->getMeta();

        return $response;
    }

    /**
     * Transformar respuesta de error
     */
    private function transformErrorResponse(array $data, int $statusCode): array
    {
        $response = [
            'success' => false,
            'message' => $data['message'] ?? $this->getDefaultErrorMessage($statusCode),
        ];

        // Agregar errores de validación si existen
        if (isset($data['errors'])) {
            $response['errors'] = $data['errors'];
        }

        // Agregar tipo de error (RFC 7807)
        $response['type'] = $this->getErrorType($statusCode);

        // Agregar metadatos
        $response['meta'] = $this->getMeta();

        return $response;
    }

    /**
     * Obtener mensaje de éxito por defecto según código de estado
     */
    private function getDefaultSuccessMessage(int $statusCode): string
    {
        return match ($statusCode) {
            200 => 'Operación exitosa',
            201 => 'Recurso creado exitosamente',
            202 => 'Petición aceptada',
            204 => 'Operación exitosa sin contenido',
            default => 'Operación exitosa',
        };
    }

    /**
     * Obtener mensaje de error por defecto según código de estado
     */
    private function getDefaultErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Solicitud incorrecta',
            401 => 'No autenticado',
            403 => 'No autorizado',
            404 => 'Recurso no encontrado',
            405 => 'Método no permitido',
            409 => 'Conflicto',
            422 => 'Error de validación',
            429 => 'Demasiadas solicitudes',
            500 => 'Error interno del servidor',
            502 => 'Error de puerta de enlace',
            503 => 'Servicio no disponible',
            504 => 'Tiempo de espera agotado',
            default => 'Error en la operación',
        };
    }

    /**
     * Obtener tipo de error según código de estado (RFC 7807)
     */
    private function getErrorType(int $statusCode): string
    {
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
    }

    /**
     * Obtener metadatos para la respuesta
     */
    private function getMeta(): array
    {
        $meta = [
            'timestamp' => now()->toIso8601String(),
        ];

        // Agregar trace ID si existe
        if ($traceId = request()->header('X-Trace-ID')) {
            $meta['request_id'] = $traceId;
        }

        return $meta;
    }

    /**
     * Obtener headers para la respuesta
     */
    private function getHeaders(Response $response): array
    {
        $headers = [];

        // Copiar headers importantes de la respuesta original
        if ($response->headers->has('X-Trace-ID')) {
            $headers['X-Trace-ID'] = $response->headers->get('X-Trace-ID');
        }

        return $headers;
    }
}
