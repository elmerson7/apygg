<?php

namespace App\Models\Mail;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MailLog Model
 *
 * Registro de cada correo que la aplicación intenta enviar: a quién, con qué
 * plantilla, desde qué cuenta y en qué estado terminó.
 *
 * Sin este registro no hay forma de responder a "¿le llegó el correo?" sin
 * entrar al panel del proveedor. Con los webhooks activos, el estado se
 * actualiza solo (entregado, rebotado, abierto).
 *
 * Usa ID auto-incrementable (tabla de alto volumen, se pagina por fecha).
 */
class MailLog extends Model
{
    use HasFactory;

    protected $table = 'mail_logs';

    /** Creado, aún no enviado */
    public const STATUS_PENDING = 'pending';

    /** Programado para más tarde */
    public const STATUS_SCHEDULED = 'scheduled';

    /** Aceptado por el proveedor */
    public const STATUS_SENT = 'sent';

    /** Entregado en el buzón del destinatario (vía webhook) */
    public const STATUS_DELIVERED = 'delivered';

    /** Rechazado de forma permanente */
    public const STATUS_BOUNCED = 'bounced';

    /** Error al enviar */
    public const STATUS_FAILED = 'failed';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SCHEDULED,
        self::STATUS_SENT,
        self::STATUS_DELIVERED,
        self::STATUS_BOUNCED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'mail_account_id',
        'template_key',
        'to_email',
        'to_name',
        'recipient_type',
        'subject',
        'status',
        'error_message',
        'provider_message_id',
        'metadata',
        'scheduled_at',
        'sent_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /**
     * Eventos recibidos del proveedor para este envío.
     */
    public function events(): HasMany
    {
        return $this->hasMany(MailEvent::class, 'mail_log_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSent($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_BOUNCED]);
    }

    public function scopeForDateRange($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeForTemplate($query, string $templateKey)
    {
        return $query->where('template_key', $templateKey);
    }

    /**
     * Número de correos enviados hoy (para el límite diario).
     */
    public static function sentToday(): int
    {
        return self::query()
            ->whereDate('created_at', today())
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_PENDING])
            ->count();
    }
}
