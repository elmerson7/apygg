<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'checksum',
        'visibility',
        'meta',
        'status',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Estados posibles del archivo
     */
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_INFECTED = 'infected';
    public const STATUS_FAILED = 'failed';

    /**
     * Tipos de archivo
     */
    public const TYPE_AVATAR = 'avatar';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_GENERAL = 'general';
    public const TYPE_TEMP = 'temp';

    /**
     * Visibilidades
     */
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC = 'public';

    protected $attributes = [
        'disk' => 's3',
        'visibility' => self::VISIBILITY_PRIVATE,
        'status' => self::STATUS_UPLOADING,
    ];

    /**
     * Relación con el usuario propietario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para archivos por estado
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para archivos por tipo
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('meta->type', $type);
    }

    /**
     * Scope para archivos verificados
     */
    public function scopeVerified($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    /**
     * Obtiene la URL completa del archivo
     */
    public function getUrl(int $expiration = 3600): ?string
    {
        if ($this->status !== self::STATUS_VERIFIED) {
            return null;
        }

        $disk = Storage::disk($this->disk);

        if ($this->visibility === self::VISIBILITY_PUBLIC) {
            return $disk->url($this->path);
        }

        // URL temporal para archivos privados
        return $disk->temporaryUrl($this->path, Carbon::now()->addSeconds($expiration));
    }

    /**
     * Obtiene el tipo de archivo basado en MIME
     */
    public function getType(): string
    {
        return $this->meta['type'] ?? self::TYPE_GENERAL;
    }

    /**
     * Verifica si el archivo es una imagen
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Verifica si el archivo es un documento
     */
    public function isDocument(): bool
    {
        return in_array($this->mime_type, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Obtiene el tamaño formateado
     */
    public function getFormattedSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * Marca el archivo como verificado
     */
    public function markAsVerified(): void
    {
        $this->update(['status' => self::STATUS_VERIFIED]);
    }

    /**
     * Marca el archivo como infectado
     */
    public function markAsInfected(): void
    {
        $this->update(['status' => self::STATUS_INFECTED]);
    }

    /**
     * Marca el archivo como fallido
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    /**
     * Verifica si el archivo existe en el storage
     */
    public function existsInStorage(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Elimina el archivo del storage
     */
    public function deleteFromStorage(): bool
    {
        if ($this->existsInStorage()) {
            return Storage::disk($this->disk)->delete($this->path);
        }

        return true;
    }

    /**
     * Mueve el archivo a cuarentena
     */
    public function moveToQuarantine(): bool
    {
        if (!$this->existsInStorage()) {
            return false;
        }

        $quarantinePath = 'quarantine/' . date('Y/m/d') . '/' . $this->id . '_' . basename($this->path);
        
        $content = Storage::disk($this->disk)->get($this->path);
        $moved = Storage::disk('s3_quarantine')->put($quarantinePath, $content);
        
        if ($moved) {
            $this->deleteFromStorage();
            $this->update([
                'meta' => array_merge($this->meta ?? [], [
                    'quarantine_path' => $quarantinePath,
                    'quarantined_at' => now()->toISOString(),
                ])
            ]);
        }

        return $moved;
    }

    /**
     * Genera el path para el archivo basado en el tipo
     */
    public static function generatePath(string $type, string $extension, ?string $userId = null): string
    {
        $year = date('Y');
        $month = date('m');
        $ulid = \Illuminate\Support\Str::ulid();
        
        $filename = $ulid . '.' . $extension;
        
        return "{$type}/{$year}/{$month}/{$filename}";
    }

    /**
     * Determina el disco apropiado basado en el tipo
     */
    public static function getDiskForType(string $type): string
    {
        return match ($type) {
            self::TYPE_AVATAR => 's3_avatars',
            self::TYPE_DOCUMENT => 's3_documents',
            self::TYPE_TEMP => 's3_temp',
            default => 's3',
        };
    }

    /**
     * Valida el MIME type según el tipo de archivo
     */
    public static function isValidMimeForType(string $mimeType, string $type): bool
    {
        $allowedMimes = match ($type) {
            self::TYPE_AVATAR => explode(',', config('filesystems.allowed_avatar_mimes', 'jpg,jpeg,png,gif')),
            self::TYPE_DOCUMENT => explode(',', config('filesystems.allowed_document_mimes', 'pdf,doc,docx')),
            default => explode(',', config('filesystems.allowed_mimes', 'jpg,jpeg,png,gif,pdf,doc,docx')),
        };

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => null,
        };

        return $extension && in_array($extension, $allowedMimes);
    }
}
