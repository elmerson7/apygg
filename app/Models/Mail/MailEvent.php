<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * MailEvent Model
 *
 * Evento recibido por webhook del proveedor de correo (entregado, abierto,
 * rebotado, marcado como spam…). Se guarda el payload completo para poder
 * auditar después, y `provider_event_id` garantiza la idempotencia: los
 * proveedores reintentan los webhooks y el mismo evento puede llegar dos veces.
 */
class MailEvent extends Model
{
    protected $table = 'mail_events';

    protected $fillable = [
        'mail_log_id',
        'provider_message_id',
        'provider_event_id',
        'type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<MailLog, $this>
     */
    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }

    /**
     * Inserta el evento de forma atómica: la restricción única de `provider_event_id` decide
     * quién gana. Devuelve null si ya existía (duplicado). La transacción (savepoint si ya hay
     * una) evita que el fallo aborte la transacción externa en PostgreSQL.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function recordOnce(array $attributes): ?self
    {
        try {
            return DB::transaction(fn () => self::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
