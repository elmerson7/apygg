<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\Search\SearchResource;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * SearchController
 *
 * Controlador para búsqueda global en múltiples modelos.
 * Utiliza Laravel Scout y Meilisearch para búsqueda full-text.
 */
class SearchController extends Controller
{
    protected array $modelResources = [
        User::class => UserResource::class,
    ];

    /**
     * Realizar búsqueda global en múltiples modelos
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $query = $request->input('q');
        $modelsToSearch = $request->getModelsToSearch();
        $filters = $request->input('filters', []);
        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
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
     * @return array<string, mixed>
     */
    protected function searchModel(
        string $modelClass,
        string $query,
        array $filters,
        int $perPage,
        int $page
    ): array {
        if (! $this->isModelSearchable($modelClass)) {
            return ['model' => class_basename($modelClass), 'total' => 0, 'data' => []];
        }

        $builder = $modelClass::search($query);

        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $builder->where($field, $value);
            }
        }

        $paginatedResults = $builder->paginate($perPage, 'page', $page);
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
     * Verificar si el modelo usa el trait Searchable (FQCN inline — evita error fatal)
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

    protected function getModelKey(string $modelClass): string
    {
        return [
            User::class => 'users',
        ][$modelClass] ?? strtolower(class_basename($modelClass));
    }
}
