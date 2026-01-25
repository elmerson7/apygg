<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ErrorLog Model
 *
 * Modelo para registrar errores y excepciones del sistema.
 * Usa ID auto-incrementable como primary key (no UUID) según estrategia del proyecto.
 */
class ErrorLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'logs_error';

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
        'trace_id',
        'user_id',
        'exception_class',
        'message',
        'file',
        'line',
        'stack_trace',
        'context',
        'severity',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'context' => 'array',
        'line' => 'integer',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Niveles de severidad disponibles
     */
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Relación con User (opcional, puede ser null)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Relación con ApiLog a través de trace_id
     */
    public function apiLog(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Logs\ApiLog::class, 'trace_id', 'trace_id');
    }

    /**
     * Scope para filtrar por trace_id
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTraceId($query, string $traceId)
    {
        return $query->where('trace_id', $traceId);
    }

    /**
     * Scope para filtrar por usuario
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUserId($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar por severidad
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope para filtrar errores no resueltos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope para filtrar errores resueltos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope para filtrar errores críticos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope para filtrar por rango de fechas
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
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
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Marcar error como resuelto
     */
    public function markAsResolved(): bool
    {
        return $this->update(['resolved_at' => now()]);
    }

    /**
     * Verificar si el error está resuelto
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
