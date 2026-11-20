<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Exportación de datos pedida por un usuario (docs/export-import.md).
 *
 * NO usa `BelongsToTenant` a propósito: una exportación de PLATAFORMA (admin) no tiene tenant
 * (`tenant_id` null) y con multiempresa activa el trait lo rechazaría. El aislamiento se hace
 * con `visibleTo()` (dueño + tenant activo) y lo cubre `ExportarAislamientoTest`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $exporter
 * @property string $format
 * @property array<string, mixed>|null $filters
 * @property string $status
 * @property int|null $total_rows
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size
 * @property string|null $error
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
class DataExport extends EloquentModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'data_exports';

    protected $fillable = [
        'tenant_id', 'user_id', 'exporter', 'format', 'filters', 'status', 'total_rows', 'disk', 'path',
        'filename', 'size', 'error', 'started_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'total_rows' => 'integer',
        'size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Solo las exportaciones del usuario y del tenant indicado (null = plataforma).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, int $userId, ?int $tenantId): Builder
    {
        $query->where('user_id', $userId);

        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Reclama la exportación con un UPDATE atómico (pending -> processing). false = otro worker la tiene
     * o ya terminó: el job no la repite.
     */
    public function claim(): bool
    {
        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_PROCESSING, 'started_at' => now(), 'updated_at' => DB::raw('now()')]);

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }
}
