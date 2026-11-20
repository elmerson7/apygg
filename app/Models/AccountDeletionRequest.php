<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Solicitud de borrado de cuenta (docs/privacy.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property CarbonInterface $requested_at
 * @property CarbonInterface $scheduled_for
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $completed_at
 * @property string|null $failure_reason
 * @property string|null $ip_address
 */
class AccountDeletionRequest extends EloquentModel
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
