<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class AuthEvent extends Model
{
    use HasUlids;

    /**
     * The connection name for the model.
     */
    protected $connection = 'logs';

    /**
     * The table associated with the model.
     */
    protected $table = 'auth_events';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'event',
        'result',
        'reason',
        'jti',
        'ip',
        'user_agent',
        'meta',
        'trace_id',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
        'user_id' => 'integer',
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
     * Get the user associated with this auth event.
     * Note: This references the main database users table.
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope for filtering by event type.
     */
    public function scopeEvent($query, $event)
    {
        return $query->where('event', $event);
    }

    /**
     * Scope for filtering by result.
     */
    public function scopeResult($query, $result)
    {
        return $query->where('result', $result);
    }

    /**
     * Scope for successful events.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('result', 'success');
    }

    /**
     * Scope for failed events.
     */
    public function scopeFailed($query)
    {
        return $query->where('result', 'failed');
    }

    /**
     * Scope for blocked events.
     */
    public function scopeBlocked($query)
    {
        return $query->where('result', 'blocked');
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
