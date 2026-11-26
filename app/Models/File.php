<?php

namespace App\Models;

use App\Contracts\FileScanServiceInterface;
use App\Enums\FileScanStatus;
use App\Jobs\ScanFileJob;
use App\Traits\BelongsToTenant;
use App\Traits\LogsActivity;
use App\Traits\SoftDeletesWithUser;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * File Model
 *
 * Modelo para gestión de archivos subidos al sistema.
 * Almacena metadatos de archivos y permite políticas de retención.
 *
 * Escaneo antivirus (docs/files.md): `scan_status` nace `pending` con el escaneo encendido (se encola `ScanFileJob`)
 * o `skipped` si está apagado. Solo `skipped` y `clean` tienen URL y se pueden descargar.
 *
 * @property FileScanStatus $scan_status
 * @property Carbon|null $scanned_at
 * @property string|null $scan_result
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes, SoftDeletesWithUser;

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
        'scan_status' => FileScanStatus::class,
        'scanned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Todo archivo nuevo, venga de donde venga (subida normal, directa, por partes), pasa por aquí.
        static::creating(function (File $file): void {
            if ($file->getAttribute('scan_status') === null) {
                $file->scan_status = app(FileScanServiceInterface::class)->initialStatus();
            }
        });

        static::created(function (File $file): void {
            if ($file->scan_status === FileScanStatus::Pending) {
                ScanFileJob::dispatch((int) $file->getKey());
            }
        });
    }

    /**
     * Se puede descargar o enlazar: subido sin escaneo o escaneado y limpio. El resto está en cuarentena.
     */
    public function isAvailable(): bool
    {
        $status = $this->getAttribute('scan_status');

        return ! $status instanceof FileScanStatus || $status->isAvailable();
    }

    /**
     * Relación con User
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para filtrar por tipo
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope para filtrar por categoría
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope para archivos públicos
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope para archivos privados
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }

    /**
     * Scope para archivos expirados
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /**
     * Scope para archivos no expirados
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
        $pow = (int) floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    /**
     * Verificar si el archivo existe físicamente
     */
    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * URL pública del archivo. Un archivo privado NO tiene URL pública (se descarga por
     * `files.download` o con una URL temporal firmada).
     */
    public function getUrl(): ?string
    {
        if (! $this->is_public || ! $this->isAvailable()) {
            return null;
        }

        if ($this->url) {
            return $this->url;
        }

        if ($this->exists()) {
            return Storage::disk($this->disk)->url($this->path);
        }

        return null;
    }
}
