<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class RbacAuditLog extends Model
{
    use HasUlids;

    /**
     * The connection name for the model.
     */
    protected $connection = 'logs';

    /**
     * The table associated with the model.
     */
    protected $table = 'rbac_audit_logs';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'actor_id',
        'user_id',
        'action',
        'role',
        'permission',
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
        'actor_id' => 'integer',
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
     * Get the actor (who performed the action).
     * Note: This references the main database users table.
     */
    public function actor()
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }

    /**
     * Get the user (who was affected by the action).
     * Note: This references the main database users table.
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope for filtering by action.
     */
    public function scopeAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for filtering by role.
     */
    public function scopeRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope for filtering by permission.
     */
    public function scopePermission($query, $permission)
    {
        return $query->where('permission', $permission);
    }

    /**
     * Scope for filtering by actor.
     */
    public function scopeByActor($query, $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    /**
     * Scope for filtering by affected user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by trace ID.
     */
    public function scopeTraceId($query, $traceId)
    {
        return $query->where('trace_id', $traceId);
    }
}
