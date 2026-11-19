<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitación a un tenant (docs/tenant-onboarding.md). Solo guarda el HASH del token (sha256):
 * el token en claro existe únicamente en el correo y en la respuesta de quien lo genera nunca sale.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property int|null $accepted_by
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 */
class TenantInvitation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'email',
        'role',
        'token_hash',
        'invited_by',
        'accepted_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Pendientes: ni aceptadas ni revocadas (aunque estén caducadas).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsable($query)
    {
        return $query->pending()->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }
}
