<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * RespondsWithApi
 *
 * Respuestas estándar de la API. Vive en un trait para que la comparta tanto
 * el `Controller` base con andamiaje CRUD como `ApiController`, que no lo tiene.
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
            $response['data'] = $this->resource && class_exists($this->resource)
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
     */
    protected function sendPaginated(LengthAwarePaginator $paginator): JsonResponse
    {
        $data = $paginator->items();

        if ($this->resource && class_exists($this->resource)) {
            $data = $this->resource::collection($data);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
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
        ]);
    }
}
