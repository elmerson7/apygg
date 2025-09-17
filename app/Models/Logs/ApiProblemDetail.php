<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class ApiProblemDetail extends Model
{

    /**
     * The connection name for the model.
     */
    protected $connection = 'logs';

    /**
     * The table associated with the model.
     */
    protected $table = 'api_problem_details';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'type',
        'title',
        'status',
        'detail',
        'instance',
        'user_id',
        'ip',
        'user_agent',
        'context',
        'trace_id',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
        'user_id' => 'string', // Cambiado a string para ULIDs
        'status' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Get the user associated with this API problem.
     * Note: This references the main database users table.
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope for filtering by problem type.
     */
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for filtering by HTTP status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for client errors (4xx).
     */
    public function scopeClientErrors($query)
    {
        return $query->whereBetween('status', [400, 499]);
    }

    /**
     * Scope for server errors (5xx).
     */
    public function scopeServerErrors($query)
    {
        return $query->whereBetween('status', [500, 599]);
    }

    /**
     * Scope for filtering by user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by IP.
     */
    public function scopeFromIp($query, $ip)
    {
        return $query->where('ip', $ip);
    }

    /**
     * Scope for filtering by trace ID.
     */
    public function scopeTraceId($query, $traceId)
    {
        return $query->where('trace_id', $traceId);
    }
}
