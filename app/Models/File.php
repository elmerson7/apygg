<?php

namespace App\Models;

use App\Traits\LogsActivity;
use App\Traits\SoftDeletesWithUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * File Model
 *
 * Modelo para gestión de archivos subidos al sistema.
 * Almacena metadatos de archivos y permite políticas de retención.
 */
class File extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes, SoftDeletesWithUser;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'filename',
        'path',
        'url',
        'disk',
        'mime_type',
        'extension',
        'size',
        'type',
        'category',
        'description',
        'metadata',
        'is_public',
        'expires_at',
        'deleted_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'size' => 'integer',
        'is_public' => 'boolean',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deleted_by' => 'string',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope para filtrar por categoría
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope para archivos públicos
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope para archivos privados
     */
    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }

    /**
     * Scope para archivos expirados
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /**
     * Scope para archivos no expirados
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now());
        });
    }

    /**
     * Scope para archivos del usuario autenticado
     */
    public function scopeOwnedBy($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Verificar si el archivo está expirado
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Obtener tamaño formateado (KB, MB, GB)
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    /**
     * Verificar si el archivo existe físicamente
     */
    public function exists(): bool
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Obtener URL del archivo
     */
    public function getUrl(): ?string
    {
        if ($this->url) {
            return $this->url;
        }

        if ($this->exists()) {
            return \Illuminate\Support\Facades\Storage::disk($this->disk)->url($this->path);
        }

        return null;
    }
}
