<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{

    /**
     * The connection name for the model.
     */
    protected $connection = 'logs';

    /**
     * The table associated with the model.
     */
    protected $table = 'security_events';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'severity',
        'event',
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
     * Get the user associated with this security event.
     * Note: This references the main database users table.
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope for filtering by severity.
     */
    public function scopeSeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope for critical events.
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope for high severity events.
     */
    public function scopeHigh($query)
    {
        return $query->where('severity', 'high');
    }

    /**
     * Scope for medium severity events.
     */
    public function scopeMedium($query)
    {
        return $query->where('severity', 'medium');
    }

    /**
     * Scope for low severity events.
     */
    public function scopeLow($query)
    {
        return $query->where('severity', 'low');
    }

    /**
     * Scope for filtering by event type.
     */
    public function scopeEvent($query, $event)
    {
        return $query->where('event', $event);
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
