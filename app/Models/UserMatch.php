<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserMatch Model
 *
 * Represents a match between two users.
 *
 * @property string $id
 * @property string $user_id
 * @property string $target_id
 * @property string $status
 * @property \Carbon\Carbon|null $matched_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class UserMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'user_id',
        'target_id',
        'status',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}
