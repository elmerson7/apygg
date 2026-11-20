<?php

namespace App\Models;

use App\Traits\RecordsTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Una entrega: una notificación a un usuario por un canal. El UNIQUE (notification_id, user_id, channel)
 * es el reclamo idempotente (`NotificationDeliveryService::claim`). Registra `tenant_id` sin aislar
 * (como los logs: la plataforma ve todo; ninguna ruta de usuario la expone).
 *
 * @property int $id
 * @property string $notification_id
 * @property int $user_id
 * @property int|null $tenant_id
 * @property string $type
 * @property string $channel
 * @property string $status
 * @property int $attempts
 * @property string|null $skipped_reason
 * @property string|null $error
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $sent_at
 */
class NotificationDelivery extends EloquentModel
{
    use RecordsTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = ['notification_id', 'user_id', 'tenant_id', 'type', 'channel', 'status', 'attempts', 'skipped_reason', 'error', 'claimed_at', 'sent_at'];

    protected $casts = ['claimed_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
}
