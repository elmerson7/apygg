<?php

namespace App\Models;

use App\Enums\TenantMembershipStatus;
use App\Enums\TenantStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant: organización o empresa de un SaaS multiempresa (docs/multi-tenancy.md).
 *
 * El tenant ACTIVO no vive aquí sino en `App\Support\Tenancy\TenantContext` (scoped, nunca
 * estático). Este modelo no usa `BelongsToTenant`: es el propio tenant.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $domain
 * @property TenantStatus $status
 * @property int|null $parent_id
 * @property array<string, mixed>|null $settings
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $currency
 * @property CarbonInterface|null $trial_ends_at
 */
class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'status',
        'parent_id',
        'settings',
        'locale',
        'timezone',
        'currency',
        'trial_ends_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => TenantStatus::class,
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Miembros del tenant (cualquier estado de membresía).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['status', 'role', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Tenant padre (agencia/marca) en la jerarquía opcional. Solo es un dato: no concede acceso.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function hasActiveMember(User|int $user): bool
    {
        return $this->users()
            ->whereKey($user instanceof User ? $user->getKey() : $user)
            ->wherePivot('status', TenantMembershipStatus::Active->value)
            ->exists();
    }

    /**
     * Tenants operativos (estado `active`).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOperational($query)
    {
        return $query->where('status', TenantStatus::Active->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
