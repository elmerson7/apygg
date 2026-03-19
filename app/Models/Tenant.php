<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant Model
 *
 * Representa una organización o inquilino en el sistema multi-tenant.
 */
class Tenant extends Model
{
    /**
     * Tenant actual resuelto por el middleware
     */
    protected static ?Tenant $currentTenant = null;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Usuarios pertenecientes a este tenant
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    /**
     * Establecer el tenant actual
     */
    public static function setCurrent(Tenant $tenant): void
    {
        static::$currentTenant = $tenant;
    }

    /**
     * Obtener el tenant actual
     */
    public static function resolveCurrent(): ?Tenant
    {
        return static::$currentTenant;
    }

    /**
     * Limpiar el tenant actual
     */
    public static function clearCurrent(): void
    {
        static::$currentTenant = null;
    }

    /**
     * Verificar si hay un tenant activo
     */
    public static function hasCurrent(): bool
    {
        return static::$currentTenant !== null;
    }

    /**
     * Scope para tenants activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Buscar tenant por dominio
     */
    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /**
     * Buscar tenant por slug
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
