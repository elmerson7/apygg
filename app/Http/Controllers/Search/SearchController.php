<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\Search\SearchResource;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * SearchController
 *
 * Controlador para búsqueda global en múltiples modelos.
 * Utiliza Laravel Scout y Meilisearch para búsqueda full-text.
 */
class SearchController extends Controller
{
    /**
     * Mapeo de modelos a sus resources correspondientes
     */
    protected array $modelResources = [
        User::class => UserResource::class,
    ];

    /**
     * Realizar búsqueda global en múltiples modelos
     *
     * @param  SearchRequest  $request
     * @return JsonResponse
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $query = $request->input('q');
        $modelsToSearch = $request->getModelsToSearch();
        $filters = $request->input('filters', []);
        $perPage = min(max(1, (int) $request->input('per_page', 15)), 100);
        $page = max(1, (int) $request->input('page', 1));

        $results = [];

        foreach ($modelsToSearch as $modelKey => $modelClass) {
            $modelFilters = $filters[$modelKey] ?? [];
            $modelResults = $this->searchModel($modelClass, $query, $modelFilters, $perPage, $page);

            if ($modelResults['total'] > 0) {
                $results[$modelKey] = $modelResults;
            }
        }

        $resource = SearchResource::fromSearchResults($query, $results, [
            'per_page' => $perPage,
            'page' => $page,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Búsqueda completada exitosamente',
            'data' => $resource,
        ]);
    }

    /**
     * Buscar en un modelo específico
     *
     * @param  string  $modelClass
     * @param  string  $query
     * @param  array  $filters
     * @param  int  $perPage
     * @param  int  $page
     * @return array<string, mixed>
     */
    protected function searchModel(
        string $modelClass,
        string $query,
        array $filters,
        int $perPage,
        int $page
    ): array {
        // Verificar que el modelo use el trait Searchable
        if (! $this->isModelSearchable($modelClass)) {
            return [
                'model' => class_basename($modelClass),
                'total' => 0,
                'data' => [],
            ];
        }

        // Realizar búsqueda con Scout
        $builder = $modelClass::search($query);

        // Aplicar filtros si existen
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $builder->where($field, $value);
            }
        }

        // Paginar resultados
        $paginatedResults = $builder->paginate($perPage, 'page', $page);

        // Transformar resultados usando el resource correspondiente
        $resourceClass = $this->modelResources[$modelClass] ?? null;
        $transformedData = $paginatedResults->items();

        if ($resourceClass && class_exists($resourceClass)) {
            $transformedData = $resourceClass::collection($paginatedResults->items());
        }

        return [
            'model' => class_basename($modelClass),
            'model_key' => $this->getModelKey($modelClass),
            'total' => $paginatedResults->total(),
            'per_page' => $paginatedResults->perPage(),
            'current_page' => $paginatedResults->currentPage(),
            'last_page' => $paginatedResults->lastPage(),
            'data' => $transformedData,
        ];
    }

    /**
     * Verificar si un modelo es searchable
     *
     * @param  string  $modelClass
     * @return bool
     */
    protected function isModelSearchable(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        $traits = class_uses_recursive($modelClass);

        return isset($traits[\Laravel\Scout\Searchable::class]) ||
            isset($traits[\App\Traits\Searchable::class]);
    }

    /**
     * Obtener la clave del modelo para usar en respuestas
     *
     * @param  string  $modelClass
     * @return string
     */
    protected function getModelKey(string $modelClass): string
    {
        $availableModels = [
            User::class => 'users',
        ];

        return $availableModels[$modelClass] ?? strtolower(class_basename($modelClass));
    }
}
