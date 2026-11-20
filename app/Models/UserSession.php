<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de un usuario (un login). Ver docs/sessions-and-2fa.md.
 *
 * No usa `BelongsToTenant`: la identidad y sus sesiones son globales (una persona puede
 * pertenecer a varios tenants); el acceso se acota siempre por `user_id`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string|null $device
 * @property string|null $user_agent
 * @property string|null $ip
 * @property string|null $refresh_jti
 * @property string|null $previous_refresh_jti
 * @property CarbonInterface|null $refresh_rotated_at
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonInterface $created_at
 */
class UserSession extends EloquentModel
{
    protected $table = 'user_sessions';

    /** @var list<string> */
    protected $fillable = ['public_id', 'user_id', 'device', 'user_agent', 'ip', 'refresh_jti', 'last_used_at'];

    /** @var list<string> */
    protected $hidden = ['refresh_jti', 'previous_refresh_jti'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'refresh_rotated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
