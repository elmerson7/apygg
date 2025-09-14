<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class FileAccessLog extends Model
{
    use HasUlids;

    /**
     * The connection name for the model.
     */
    protected $connection = 'logs';

    /**
     * The table associated with the model.
     */
    protected $table = 'file_access_logs';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'file_id',
        'action',
        'ip',
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
        'file_id' => 'integer',
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
     * Get the user associated with this file access log.
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
     * Scope for upload actions.
     */
    public function scopeUploads($query)
    {
        return $query->where('action', 'upload');
    }

    /**
     * Scope for download actions.
     */
    public function scopeDownloads($query)
    {
        return $query->where('action', 'download');
    }

    /**
     * Scope for delete actions.
     */
    public function scopeDeletes($query)
    {
        return $query->where('action', 'delete');
    }

    /**
     * Scope for view actions.
     */
    public function scopeViews($query)
    {
        return $query->where('action', 'view');
    }

    /**
     * Scope for filtering by user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by file.
     */
    public function scopeForFile($query, $fileId)
    {
        return $query->where('file_id', $fileId);
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
