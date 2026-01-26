<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base Controller
 *
 * Clase base para todos los controladores de la aplicación.
 * Proporciona métodos CRUD comunes y respuestas estándar.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = null;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = null;

    /**
     * Relaciones permitidas para eager loading
     */
    protected array $allowedRelations = [];

    /**
     * Campos permitidos para ordenamiento
     */
    protected array $allowedSortFields = [];

    /**
     * Campos permitidos para filtrado
     */
    protected array $allowedFilterFields = [];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->getModelQuery();

        // Aplicar filtros
        $query = $this->applyFilters($query, $request);

        // Aplicar ordenamiento
        $query = $this->applySorting($query, $request);

        // Cargar relaciones
        $query = $this->loadRelations($query, $request);

        // Paginar resultados
        $perPage = $request->get('per_page', 15);
        $perPage = min(max(1, (int) $perPage), 100); // Entre 1 y 100

        $items = $query->paginate($perPage);

        return $this->sendPaginated($items);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $query = $this->getModelQuery();
        $query = $this->loadRelations($query, $request);

        $item = $query->findOrFail($id);

        return $this->sendSuccess($item, 'Recurso obtenido exitosamente');
    }

    /**
     * Store a newly created resource in storage.
     *
     * Nota: Este método debe ser sobrescrito en controladores específicos
     * con FormRequest para validación adecuada. Los controladores hijos pueden
     * usar tipos más específicos (FormRequest) sin problemas de compatibilidad.
     */
    public function store(Request $request): JsonResponse
    {
        // Implementación genérica - debe ser sobrescrita en controladores específicos
        if ($this->model === null) {
            throw new \RuntimeException('Método store() debe ser implementado en el controlador hijo');
        }

        $validated = $request->all();
        $item = $this->getModel()::create($validated);

        return $this->sendSuccess($item, 'Recurso creado exitosamente', 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * Nota: Este método debe ser sobrescrito en controladores específicos
     * con FormRequest para validación adecuada. Los controladores hijos pueden
     * usar tipos más específicos (FormRequest) sin problemas de compatibilidad.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        // Implementación genérica - debe ser sobrescrita en controladores específicos
        if ($this->model === null) {
            throw new \RuntimeException('Método update() debe ser implementado en el controlador hijo');
        }

        $item = $this->getModelQuery()->findOrFail($id);
        $validated = $request->all();

        $item->update($validated);

        return $this->sendSuccess($item, 'Recurso actualizado exitosamente');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $item = $this->getModelQuery()->findOrFail($id);
        $item->delete();

        return $this->sendSuccess(null, 'Recurso eliminado exitosamente');
    }

    /**
     * Cargar relaciones mediante eager loading
     */
    protected function loadRelations($query, Request $request)
    {
        $relations = $request->get('include', '');

        if (empty($relations)) {
            return $query;
        }

        $requestedRelations = explode(',', $relations);
        $allowedRelations = array_intersect($requestedRelations, $this->allowedRelations);

        if (! empty($allowedRelations)) {
            $query->with($allowedRelations);
        }

        return $query;
    }

    /**
     * Aplicar filtros a la consulta
     */
    protected function applyFilters($query, Request $request)
    {
        $filters = $request->only($this->allowedFilterFields);

        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    /**
     * Aplicar ordenamiento a la consulta
     */
    protected function applySorting($query, Request $request)
    {
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');

        if (in_array($sortField, $this->allowedSortFields)) {
            $query->orderBy($sortField, in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'desc');
        }

        return $query;
    }

    /**
     * Enviar respuesta exitosa
     */
    protected function sendSuccess($data = null, string $message = 'Operación exitosa', int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            if ($this->resource && class_exists($this->resource)) {
                $response['data'] = new $this->resource($data);
            } else {
                $response['data'] = $data;
            }
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Enviar respuesta de error
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
     * Enviar respuesta paginada
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

    /**
     * Obtener instancia del modelo
     */
    protected function getModel(): string
    {
        if ($this->model === null) {
            throw new \RuntimeException('Modelo no definido en el controlador');
        }

        return $this->model;
    }

    /**
     * Obtener query builder del modelo
     */
    protected function getModelQuery()
    {
        $model = $this->getModel();

        return $model::query();
    }
}
