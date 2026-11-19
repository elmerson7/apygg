<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebhookDelivery Model
 *
 * Modelo para tracking de entregas de webhooks.
 * Registra cada intento de entrega de un webhook.
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_deliveries';

    /**
     * Estados posibles de una entrega
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'webhook_id',
        'event_type',
        'payload',
        'status',
        'response_code',
        'response_body',
        'error_message',
        'attempts',
        'delivered_at',
        'failed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'response_code' => 'integer',
        'attempts' => 'integer',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Webhook
     *
     * Una entrega pertenece a un webhook.
     *
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }

    /**
     * Scope para filtrar entregas pendientes
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope para filtrar entregas exitosas
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope para filtrar entregas fallidas
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope para filtrar por webhook
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByWebhook($query, string $webhookId)
    {
        return $query->where('webhook_id', $webhookId);
    }

    /**
     * Scope para filtrar por tipo de evento
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Verificar si la entrega fue exitosa
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Verificar si la entrega falló
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Verificar si la entrega está pendiente
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Reclama la entrega para enviarla (UPDATE atómico): solo una ejecución lo consigue.
     * Se puede reclamar si está pendiente o fallida (reintento), o si quedó "processing"
     * de una ejecución que murió hace más de 5 minutos. Nunca si ya se entregó.
     */
    public function claim(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where(function ($q) {
                $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
                    ->orWhere(fn ($q) => $q->where('status', self::STATUS_PROCESSING)
                        ->where('updated_at', '<', now()->subMinutes(5)));
            })
            ->update(['status' => self::STATUS_PROCESSING, 'updated_at' => now()]) === 1;

        if ($claimed) {
            $this->status = self::STATUS_PROCESSING;
        } else {
            $this->refresh();
        }

        return $claimed;
    }

    /**
     * Marcar como exitosa
     */
    public function markAsSuccessful(int $responseCode, ?string $responseBody = null): bool
    {
        return $this->update([
            'status' => self::STATUS_SUCCESS,
            'response_code' => $responseCode,
            'response_body' => $responseBody !== null ? mb_substr($responseBody, 0, (int) config('webhooks.max_response_body', 1000)) : null,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Marcar como fallida
     */
    public function markAsFailed(string $errorMessage, ?int $responseCode = null): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'response_code' => $responseCode,
            'error_message' => $errorMessage,
            'failed_at' => now(),
        ]);
    }

    /**
     * Incrementar número de intentos
     */
    public function incrementAttempts(): bool
    {
        $this->increment('attempts');

        return true;
    }
}
