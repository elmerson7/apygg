<?php

namespace App\Traits;

use Laravel\Scout\Searchable as ScoutSearchable;

/**
 * Trait Searchable
 *
 * Integración con Meilisearch para búsqueda full-text.
 * Requiere Laravel Scout y Meilisearch configurado.
 */
trait Searchable
{
    use ScoutSearchable;

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = $this->toArray();

        // Remover campos que no deben ser indexados
        $excludedFields = $this->getExcludedFromSearch();

        foreach ($excludedFields as $field) {
            unset($array[$field]);
        }

        return $array;
    }

    /**
     * Campos que deben ser excluidos de la búsqueda.
     * Sobrescribir en el modelo para personalizar.
     */
    protected function getExcludedFromSearch(): array
    {
        return [
            'password',
            'remember_token',
            'api_key',
            'secret',
            'deleted_at',
            'deleted_by',
        ];
    }

    /**
     * Campos que deben ser filtrables en Meilisearch.
     * Sobrescribir en el modelo para personalizar.
     */
    public function getFilterableAttributes(): array
    {
        return [];
    }

    /**
     * Campos que deben ser ordenables en Meilisearch.
     * Sobrescribir en el modelo para personalizar.
     */
    public function getSortableAttributes(): array
    {
        return ['created_at', 'updated_at'];
    }

    /**
     * Configurar índices de búsqueda en Meilisearch.
     */
    public function shouldBeSearchable(): bool
    {
        // Solo indexar si no está eliminado (soft delete)
        // El modelo User usa SoftDeletes, por lo que siempre tiene el método trashed()
        return ! $this->trashed();
    }

    /**
     * Realizar búsqueda con filtros adicionales.
     */
    public static function searchWithFilters(string $query, array $filters = []): \Laravel\Scout\Builder
    {
        $builder = static::search($query);

        // Aplicar filtros si Meilisearch los soporta
        if (! empty($filters)) {
            foreach ($filters as $field => $value) {
                $builder->where($field, $value);
            }
        }

        return $builder;
    }

    /**
     * Realizar búsqueda con ordenamiento.
     */
    public static function searchWithSort(string $query, string $sortBy = 'created_at', string $direction = 'desc'): \Laravel\Scout\Builder
    {
        return static::search($query)->orderBy($sortBy, $direction);
    }

    /**
     * Realizar búsqueda paginada.
     */
    public static function searchPaginated(string $query, int $perPage = 15, int $page = 1): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return static::search($query)
            ->paginate($perPage, 'page', $page);
    }
}
