<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Recibo de un webhook entrante (docs/integrations.md §7): lo que el proveedor envió, ya verificado.
 *
 * NO usa `BelongsToTenant` a propósito: se crea en una ruta pública antes de saber el tenant, y
 * `tenant_id` es opcional (webhooks de plataforma). El job que lo procesa restaura el tenant del
 * recibo antes de llamar al handler. La unicidad (provider, delivery_id) da la idempotencia.
 *
 * @property int $id
 * @property string $provider
 * @property string $delivery_id
 * @property int|null $tenant_id
 * @property array<string, mixed> $payload
 * @property array<string, string>|null $headers
 * @property string $status
 * @property int $attempts
 * @property CarbonInterface|null $processed_at
 * @property string|null $error
 */
class InboundWebhookReceipt extends EloquentModel
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'inbound_webhook_receipts';

    protected $fillable = ['provider', 'delivery_id', 'tenant_id', 'payload', 'headers', 'status', 'attempts', 'processed_at', 'error'];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'attempts' => 'integer',
        'tenant_id' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Inserta el recibo de forma atómica: la restricción única (provider, delivery_id) decide quién
     * gana. null = ya existía (duplicado). Nunca `exists()` + `create()`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function recordOnce(array $attributes): ?self
    {
        try {
            return DB::transaction(fn () => self::create($attributes + ['status' => self::STATUS_RECEIVED]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Reclama un intento de proceso: suma 1 a `attempts` solo si sigue pendiente (received/failed).
     * false = otro worker lo procesó ya.
     */
    public function claimAttempt(): bool
    {
        $updated = self::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_FAILED])
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }

    public function markProcessed(): void
    {
        $this->forceFill(['status' => self::STATUS_PROCESSED, 'processed_at' => now(), 'error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error' => mb_substr($error, 0, 1000)])->save();
    }
}
