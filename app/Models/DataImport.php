<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Importación de datos con vista previa (docs/export-import.md).
 *
 * Igual que `DataExport`: sin `BelongsToTenant` (hay importaciones de plataforma con `tenant_id` null);
 * el aislamiento es `visibleTo()` y lo cubre `ExportarAislamientoTest`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $importer
 * @property string $status
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string $file_hash
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $created_rows
 * @property int $skipped_rows
 * @property array<string, mixed>|null $preview
 * @property string|null $errors_path
 * @property string|null $error
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 */
class DataImport extends EloquentModel
{
    public const STATUS_VALIDATING = 'validating';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'data_imports';

    protected $fillable = [
        'tenant_id', 'user_id', 'importer', 'status', 'original_name', 'disk', 'path', 'file_hash', 'total_rows',
        'valid_rows', 'invalid_rows', 'created_rows', 'skipped_rows', 'preview', 'errors_path', 'error',
        'confirmed_at', 'completed_at',
    ];

    protected $casts = [
        'preview' => 'array',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'created_rows' => 'integer',
        'skipped_rows' => 'integer',
        'confirmed_at' => 'datetime',
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, int $userId, ?int $tenantId): Builder
    {
        $query->where('user_id', $userId);

        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    /**
     * Cambio de estado atómico: solo pasa de `$from` a `$to` si sigue en `$from`. Decide quién gana
     * cuando dos peticiones o dos workers compiten (confirmar dos veces, procesar dos veces).
     *
     * @param  string|list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    public function transition(string|array $from, string $to, array $extra = []): bool
    {
        $updated = self::query()
            ->whereKey($this->getKey())
            ->whereIn('status', (array) $from)
            ->update(['status' => $to, 'updated_at' => DB::raw('now()')] + $extra);

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }
}
