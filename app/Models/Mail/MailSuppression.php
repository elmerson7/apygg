<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * MailSuppression
 *
 * Dirección a la que NO se debe enviar: rebotó de forma permanente, se quejó de spam o la pusimos a mano.
 * Es global (una dirección no entrega correo para nadie); se alimenta de los eventos del proveedor y desde
 * el panel. La normalización y las reglas viven en `MailSuppressionService`.
 *
 * @property int $id
 * @property string $email
 * @property string $reason
 * @property string $source
 * @property array<string, mixed>|null $details
 * @property Carbon $suppressed_at
 */
class MailSuppression extends Model
{
    public const REASON_BOUNCE = 'bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_MANUAL = 'manual';

    /** @var list<string> */
    public const REASONS = [self::REASON_BOUNCE, self::REASON_COMPLAINT, self::REASON_MANUAL];

    protected $table = 'mail_suppressions';

    protected $fillable = ['email', 'reason', 'source', 'details', 'suppressed_at'];

    protected $casts = [
        'details' => 'array',
        'suppressed_at' => 'datetime',
    ];
}
