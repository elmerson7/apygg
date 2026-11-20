<?php

namespace App\Traits;

use App\Models\Model;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable as ScoutSearchable;

/**
 * Trait Searchable
 *
 * Integración con Meilisearch para búsqueda full-text.
 * Requiere Laravel Scout y Meilisearch configurado.
 */
trait Searchable
{
    use ScoutSearchable {
        search as protected scoutSearch;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = $this->toArray();

        // Modelo multiempresa: el índice lleva `tenant_id` para poder filtrar por empresa.
        if ($this->searchIsTenantScoped()) {
            $array['tenant_id'] = $this->getAttribute('tenant_id');
        }

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
     *
     * @return list<string>
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
        return $this->searchIsTenantScoped() ? ['tenant_id'] : [];
    }

    /**
     * ¿Usa el modelo `BelongsToTenant`? Entonces el índice se indexa y se filtra por `tenant_id`.
     * Un modelo que sobrescriba `toSearchableArray()` / `getFilterableAttributes()` debe incluir
     * `tenant_id` (ver docs/multi-tenancy.md, «Búsqueda»).
     */
    public function searchIsTenantScoped(): bool
    {
        return in_array(BelongsToTenant::class, class_uses_recursive($this), true);
    }

    /**
     * Búsqueda de Scout con el filtro del tenant del contexto (fail-closed: sin tenant y sin
     * `withoutTenant()` lanza `MissingTenantContext`). Con multiempresa apagado no filtra.
     *
     * @param  string  $query
     * @param  \Closure|null  $callback
     * @return Builder<static>
     */
    public static function search($query = '', $callback = null)
    {
        $builder = static::scoutSearch($query, $callback);

        if ($builder->model->searchIsTenantScoped()) {
            $context = app(TenantContext::class);

            if ($context->enforcing()) {
                $builder->where('tenant_id', $context->requireId(static::class, 'search'));
            }
        }

        return $builder;
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
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<static>
     */
    public static function searchWithFilters(string $query, array $filters = []): Builder
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
     *
     * @return Builder<static>
     */
    public static function searchWithSort(string $query, string $sortBy = 'created_at', string $direction = 'desc'): Builder
    {
        return static::search($query)->orderBy($sortBy, $direction);
    }

    /**
     * Realizar búsqueda paginada.
     *
     * @return LengthAwarePaginator<int, static>
     */
    public static function searchPaginated(string $query, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return static::search($query)
            ->paginate($perPage, 'page', $page);
    }
}
