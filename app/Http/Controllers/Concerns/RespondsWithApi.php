<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
     *
     * @param  mixed  $data
     */
    protected function sendSuccess($data = null, string $message = 'Operación exitosa', int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $this->wrapData($data);
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Envuelve en el Resource del controller un modelo suelto.
     *
     * Un array o un Resource ya vienen armados (p. ej. `['api_key' => new XResource(..), 'key' => ..]`):
     * solo se envuelven modelos. Para OpenAPI (Scramble) el tipo de salida es el de entrada: el
     * documento describe el `data` que el controller pasó, no esta indirección.
     *
     * @template TData
     *
     * @param  TData  $data
     * @return TData
     */
    private function wrapData(mixed $data): mixed
    {
        return $this->resource && ! is_array($data) && ! $data instanceof JsonResource && class_exists($this->resource)
            ? new $this->resource($data)
            : $data;
    }

    /**
     * Respuesta de error (4xx, 5xx).
     *
     * @param  array<string, mixed>  $errors
     */
    protected function sendError(string $message = 'Error en la operación', int $statusCode = 400, array $errors = [], ErrorCode|string|null $code = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'code' => ErrorCode::resolve($code, $statusCode),
        ];

        if ($requestId = request()->header('X-Trace-ID')) {
            $response['request_id'] = $requestId;
        }

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
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    protected function sendPaginated(LengthAwarePaginator $paginator, array $extraMeta = [], string|false|null $resource = null): JsonResponse
    {
        $data = $this->pageItems($paginator->items(), $resource);

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
     * Respuesta paginada por cursor (`cursorPaginate()`): para listados grandes o de append continuo
     * (feeds, logs), donde un `COUNT(*)` por página es caro y las páginas no deben saltar si entran filas.
     *
     * Misma forma que `sendPaginated` sin los totales (no se calculan): `meta` lleva `per_page`,
     * `has_more`, `next_cursor` y `prev_cursor` (opacos; se devuelven tal cual en `?cursor=`).
     * Es opt-in por endpoint; ver docs/api-contract.md.
     *
     * @param  array<string, mixed>  $extraMeta  Metadatos adicionales para `meta`.
     * @param  class-string<JsonResource>|false|null  $resource  Igual que en `sendPaginated`.
     * @param  CursorPaginator<int, mixed>  $paginator
     */
    protected function sendCursorPaginated(CursorPaginator $paginator, array $extraMeta = [], string|false|null $resource = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->pageItems($paginator->items(), $resource),
            'meta' => array_merge([
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
            ], $extraMeta),
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Los ítems de una página, envueltos en el Resource (el del argumento o el del controller).
     *
     * @param  array<int, mixed>  $items
     * @param  class-string<JsonResource>|false|null  $resource
     * @return array<int, mixed>|AnonymousResourceCollection
     */
    private function pageItems(array $items, string|false|null $resource): array|AnonymousResourceCollection
    {
        $resource ??= $this->resource;

        return $resource !== false && $resource && class_exists($resource)
            ? $resource::collection($items)
            : $items;
    }

    /**
     * Tamaño de página acotado a 1..$max (por defecto, 100).
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return min(max(1, (int) $request->input('per_page', $default)), $max);
    }
}
