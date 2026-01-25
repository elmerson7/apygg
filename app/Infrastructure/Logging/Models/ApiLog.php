<?php

namespace App\Infrastructure\Logging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApiLog Model
 *
 * Modelo para registrar todos los requests y responses de la API.
 * Usa ID auto-incrementable como primary key (no UUID) según estrategia del proyecto.
 *
 * @package App\Infrastructure\Logging\Models
 */
class ApiLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'api_logs';

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
        'request_method',
        'request_path',
        'request_query',
        'request_body',
        'request_headers',
        'response_status',
        'response_body',
        'response_time_ms',
        'user_agent',
        'ip_address',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_query' => 'array',
        'request_body' => 'array',
        'request_headers' => 'array',
        'response_body' => 'array',
        'response_time_ms' => 'integer',
        'response_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
     * Scope para filtrar por trace_id
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $traceId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTraceId($query, string $traceId)
    {
        return $query->where('trace_id', $traceId);
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
     * Scope para filtrar por método HTTP
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $method
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByMethod($query, string $method)
    {
        return $query->where('request_method', strtoupper($method));
    }

    /**
     * Scope para filtrar por código de estado
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, int $status)
    {
        return $query->where('response_status', $status);
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
     * Scope para filtrar requests lentos
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $thresholdMs Umbral en milisegundos
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSlowRequests($query, int $thresholdMs = 1000)
    {
        return $query->where('response_time_ms', '>', $thresholdMs);
    }
}
