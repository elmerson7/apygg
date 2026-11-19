<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * RespondsWithApi
 *
 * Respuestas estándar de la API. Las usa el `Controller` base; vive en un
 * trait para poder reutilizarlas fuera de la jerarquía de controladores.
 *
 * El envelope (`success` / `message` / `data` / `meta` / `links`) es el contrato
 * que espera el cliente: nunca devuelvas `response()->json()` a mano desde un
 * controlador.
 */
trait RespondsWithApi
{
    /**
     * Resource con el que envolver los datos. Quien use el trait puede
     * declararla para que sendSuccess/sendPaginated transformen la respuesta.
     */
    protected ?string $resource = null;

    /**
     * Respuesta exitosa (200, 201…).
     */
    protected function sendSuccess($data = null, string $message = 'Operación exitosa', int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            // Un array o un Resource ya vienen armados (p. ej. `['api_key' => new XResource(..), 'key' => ..]`):
            // solo se envuelven modelos.
            $response['data'] = $this->resource && ! is_array($data) && ! $data instanceof JsonResource && class_exists($this->resource)
                ? new $this->resource($data)
                : $data;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Respuesta de error (4xx, 5xx).
     *
     * @param  array<string, mixed>  $errors
     */
    protected function sendError(string $message = 'Error en la operación', int $statusCode = 400, array $errors = []): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Respuesta paginada, con metadatos y enlaces.
     *
     * @param  array<string, mixed>  $extraMeta  Metadatos adicionales para `meta`.
     * @param  class-string<JsonResource>|false|null  $resource  Resource de los ítems (por defecto, `$this->resource`; `false` devuelve los ítems tal cual).
     */
    protected function sendPaginated(LengthAwarePaginator $paginator, array $extraMeta = [], string|false|null $resource = null): JsonResponse
    {
        $data = $paginator->items();
        $resource ??= $this->resource;

        if ($resource !== false && $resource && class_exists($resource)) {
            $data = $resource::collection($data);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ], $extraMeta),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Tamaño de página acotado a 1..$max (por defecto, 100).
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return min(max(1, (int) $request->input('per_page', $default)), $max);
    }
}
