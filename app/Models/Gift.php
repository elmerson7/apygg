<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gift Model
 *
 * Represents a gift sent between users.
 *
 * @property string $id
 * @property string $sender_id
 * @property string $receiver_id
 * @property string $type
 * @property float $amount
 * @property string|null $message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Gift extends Model
{
    protected $fillable = [
        'sender_id',
        'receiver_id',
        'type',
        'amount',
        'message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
