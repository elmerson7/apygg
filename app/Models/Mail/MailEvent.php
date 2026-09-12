<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }
}
