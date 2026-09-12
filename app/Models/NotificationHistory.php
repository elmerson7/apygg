<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * NotificationHistory Model
 *
 * Historial de notificaciones enviadas por cualquier canal (correo, SMS, push,
 * base de datos). Responde a "¿se le avisó al usuario y por dónde?".
 *
 * `NotificationService` ya escribía aquí, pero el modelo no existía y las
 * llamadas quedaban en nada por una comprobación `class_exists`.
 *
 * Para el correo, el detalle fino (estado de entrega, rebotes, reintentos) vive
 * en `mail_logs`; esta tabla es la vista transversal de todos los canales.
 *
 * Usa ID auto-incrementable, como el resto de tablas de historial.
 */
class NotificationHistory extends EloquentModel
{
    use HasFactory;

    protected $table = 'notification_history';

    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_DATABASE = 'database';

    public const STATUS_SENT = 'sent';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'channel',
        'recipient',
        'subject',
        'status',
        'error_message',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
