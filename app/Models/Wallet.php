<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wallet Model
 *
 * Represents a user's wallet for credits/balance.
 *
 * @property string $id
 * @property string $user_id
 * @property float $balance
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
