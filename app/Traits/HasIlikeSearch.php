<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scope search() por texto (ILIKE de PostgreSQL) para modelos que no heredan de
 * App\Models\Model (p. ej. los de spatie/laravel-permission).
 *
 * El modelo define las columnas con getSearchableColumns().
 */
trait HasIlikeSearch
{
    /**
     * @return array<string>
     */
    abstract protected function getSearchableColumns(): array;

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>  $columns
     * @return Builder<static>
     */
    public function scopeSearch($query, string $term, array $columns = [])
    {
        $columns = $columns ?: $this->getSearchableColumns();

        return $query->where(function ($q) use ($term, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'ILIKE', "%{$term}%");
            }
        });
    }
}
