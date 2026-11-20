<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Intención de subida directa (presignada). Mientras está `pending` y no caducó, sus bytes
 * declarados cuentan contra la cuota de almacenamiento del tenant (reserva).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $name
 * @property string $filename
 * @property string $path
 * @property string $disk
 * @property string $mime_type
 * @property string $extension
 * @property int $size
 * @property string|null $checksum_sha256
 * @property string|null $category
 * @property string|null $description
 * @property bool $is_public
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property int|null $file_id
 */
class FileUploadIntent extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'user_id', 'name', 'filename', 'path', 'disk', 'mime_type', 'extension', 'size',
        'checksum_sha256', 'category', 'description', 'is_public', 'status', 'expires_at',
        'completed_at', 'file_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'size' => 'integer',
        'is_public' => 'boolean',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pendientes y vigentes: las que reservan cuota.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReserving($query)
    {
        return $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
