<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Base Model
 * 
 * Clase base para todos los modelos de la aplicación.
 * Proporciona UUID como primary key, soft deletes y scopes comunes.
 */
abstract class BaseModel extends EloquentModel
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Generar UUID automáticamente al crear
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Scope para filtrar registros activos (no eliminados)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope para filtrar registros inactivos (eliminados)
     */
    public function scopeInactive($query)
    {
        return $query->onlyTrashed();
    }

    /**
     * Scope para ordenar por más recientes
     */
    public function scopeRecent($query, string $column = 'created_at')
    {
        return $query->orderBy($column, 'desc');
    }

    /**
     * Scope para ordenar por más antiguos
     */
    public function scopeOldest($query, string $column = 'created_at')
    {
        return $query->orderBy($column, 'asc');
    }

    /**
     * Scope para filtrar por rango de fechas
     */
    public function scopeDateRange($query, string $column, $startDate = null, $endDate = null)
    {
        if ($startDate) {
            $query->where($column, '>=', $startDate);
        }

        if ($endDate) {
            $query->where($column, '<=', $endDate);
        }

        return $query;
    }

    /**
     * Scope para buscar por término en múltiples columnas
     */
    public function scopeSearch($query, string $term, array $columns = [])
    {
        if (empty($columns)) {
            $columns = $this->getSearchableColumns();
        }

        return $query->where(function ($q) use ($term, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'ILIKE', "%{$term}%");
            }
        });
    }

    /**
     * Obtener columnas buscables por defecto
     */
    protected function getSearchableColumns(): array
    {
        return ['name', 'email'];
    }

    /**
     * Verificar si el modelo está activo (no eliminado)
     */
    public function isActive(): bool
    {
        return $this->deleted_at === null;
    }

    /**
     * Verificar si el modelo está inactivo (eliminado)
     */
    public function isInactive(): bool
    {
        return $this->deleted_at !== null;
    }
}
