<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Webhook Model
 *
 * Modelo para configuración de webhooks.
 * Los webhooks permiten notificar eventos a URLs externas.
 */
class Webhook extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhooks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'url',
        'secret',
        'old_secret',
        'secret_rotated_at',
        'events',
        'status',
        'timeout',
        'max_retries',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret', // Nunca exponer el secret
        'old_secret', // Nunca exponer el secret anterior
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'events' => 'array',
        'timeout' => 'integer',
        'max_retries' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'last_triggered_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'secret_rotated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Obtener eventos disponibles para suscripción
     *
     * @return array<string>
     */
    public static function getAvailableEvents(): array
    {
        return array_keys(config('webhooks.events', []));
    }

    /**
     * Obtener clase de evento Laravel desde nombre de evento webhook
     */
    public static function getEventClass(string $eventName): ?string
    {
        return config("webhooks.events.{$eventName}");
    }

    /**
     * Obtener nombre de evento webhook desde clase de evento Laravel
     */
    public static function getEventName(string $eventClass): ?string
    {
        $events = config('webhooks.events', []);

        return array_search($eventClass, $events) ?: null;
    }

    /**
     * Relación con User
     *
     * Un webhook puede pertenecer a un usuario (opcional).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relación con WebhookDeliveries
     *
     * Un webhook tiene múltiples entregas.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }

    /**
     * Scope para filtrar webhooks activos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope para filtrar webhooks inactivos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope para filtrar webhooks pausados
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }

    /**
     * Scope para filtrar por usuario
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar webhooks que escuchan un evento específico
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForEvent($query, string $eventType)
    {
        return $query->where('status', 'active')
            ->where(function ($q) use ($eventType) {
                $q->whereNull('events')
                    ->orWhereJsonContains('events', $eventType);
            });
    }

    /**
     * Verificar si el webhook está activo
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->deleted_at === null;
    }

    /**
     * Verificar si el webhook escucha un evento específico
     */
    public function listensTo(string $eventType): bool
    {
        // Si no tiene eventos definidos, escucha todos
        if (empty($this->events)) {
            return true;
        }

        return in_array($eventType, $this->events ?? []);
    }

    /**
     * Incrementar contador de éxito
     */
    public function incrementSuccess(): bool
    {
        return $this->increment('success_count') &&
            $this->update(['last_success_at' => now()]);
    }

    /**
     * Incrementar contador de fallos
     */
    public function incrementFailure(): bool
    {
        return $this->increment('failure_count') &&
            $this->update(['last_failure_at' => now()]);
    }

    /**
     * Actualizar última vez que se activó
     */
    public function updateLastTriggered(): bool
    {
        return $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Generar secret aleatorio si no existe
     */
    public function generateSecret(): string
    {
        if (empty($this->secret)) {
            $this->secret = bin2hex(random_bytes(32));
            $this->save();
        }

        return $this->secret;
    }

    /**
     * Rotar el secret del webhook
     * Mantiene el secret anterior durante un período de gracia para evitar interrupciones
     *
     * @param  int  $gracePeriodDays  Días de período de gracia (default: 7)
     * @return array Array con el nuevo secret y fecha de expiración del secret anterior
     */
    public function rotateSecret(int $gracePeriodDays = 7): array
    {
        // Guardar secret anterior si existe
        if (! empty($this->secret)) {
            $this->old_secret = $this->secret;
            $this->secret_rotated_at = now();
        }

        // Generar nuevo secret
        $this->secret = bin2hex(random_bytes(32));
        $this->save();

        // Calcular fecha de expiración del secret anterior
        $oldSecretExpiresAt = $this->secret_rotated_at
            ? $this->secret_rotated_at->copy()->addDays($gracePeriodDays)
            : now()->addDays($gracePeriodDays);

        return [
            'new_secret' => $this->secret,
            'old_secret_expires_at' => $oldSecretExpiresAt->toIso8601String(),
            'grace_period_days' => $gracePeriodDays,
        ];
    }

    /**
     * Verificar si el secret anterior aún es válido (dentro del período de gracia)
     *
     * @param  int  $gracePeriodDays  Días de período de gracia (default: 7)
     */
    public function isOldSecretValid(int $gracePeriodDays = 7): bool
    {
        if (empty($this->old_secret) || ! $this->secret_rotated_at) {
            return false;
        }

        $expiresAt = $this->secret_rotated_at->copy()->addDays($gracePeriodDays);

        return now()->isBefore($expiresAt);
    }

    /**
     * Limpiar secret anterior después del período de gracia
     */
    public function clearOldSecret(): bool
    {
        return $this->update([
            'old_secret' => null,
            'secret_rotated_at' => null,
        ]);
    }
}
