<?php

namespace App\Infrastructure\Logging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ActivityLog Model
 *
 * Modelo para registrar cambios en modelos (auditoría).
 * Usa ID auto-incrementable como primary key (no UUID) según estrategia del proyecto.
 *
 * @package App\Infrastructure\Logging\Models
 */
class ActivityLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_logs';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'model_type',
        'model_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tipos de acciones disponibles
     */
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_RESTORED = 'restored';

    /**
     * Relación con User (opcional, puede ser null)
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Relación polimórfica con el modelo auditado
     *
     * @return MorphTo
     */
    public function model(): MorphTo
    {
        return $this->morphTo('model', 'model_type', 'model_id');
    }

    /**
     * Scope para filtrar por usuario
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUserId($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar por tipo de modelo
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $modelType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByModelType($query, string $modelType)
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * Scope para filtrar por modelo específico
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $modelType
     * @param string $modelId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByModel($query, string $modelType, string $modelId)
    {
        return $query->where('model_type', $modelType)
            ->where('model_id', $modelId);
    }

    /**
     * Scope para filtrar por acción
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope para filtrar creaciones
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreated($query)
    {
        return $query->where('action', self::ACTION_CREATED);
    }

    /**
     * Scope para filtrar actualizaciones
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUpdated($query)
    {
        return $query->where('action', self::ACTION_UPDATED);
    }

    /**
     * Scope para filtrar eliminaciones
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('action', self::ACTION_DELETED);
    }

    /**
     * Scope para filtrar restauraciones
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRestored($query)
    {
        return $query->where('action', self::ACTION_RESTORED);
    }

    /**
     * Scope para filtrar por rango de fechas
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $startDate
     * @param string|null $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, ?string $startDate = null, ?string $endDate = null)
    {
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Scope para ordenar por más recientes
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Obtener los campos que cambiaron
     *
     * @return array
     */
    public function getChangedFields(): array
    {
        if (!$this->old_values || !$this->new_values) {
            return [];
        }

        $changed = [];
        foreach ($this->new_values as $key => $newValue) {
            $oldValue = $this->old_values[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changed[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changed;
    }

    /**
     * Verificar si hubo cambios en los valores auditados
     *
     * @return bool
     */
    public function hasFieldChanges(): bool
    {
        return !empty($this->getChangedFields());
    }
}
