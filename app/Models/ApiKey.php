<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApiKey Model
 *
 * Modelo para API Keys de usuarios.
 * Las keys se almacenan con hash SHA256, nunca en texto plano.
 */
class ApiKey extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'api_keys';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'key',
        'scopes',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key', // Nunca exponer el hash de la key
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relación con User
     *
     * Una API Key pertenece a un usuario.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope para filtrar keys activas (no expiradas ni eliminadas)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope para filtrar keys expiradas
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope para filtrar por usuario
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Verificar si la key está expirada
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Verificar si la key está activa
     */
    public function isActive(): bool
    {
        return $this->deleted_at === null && ! $this->isExpired();
    }

    /**
     * Verificar si la key tiene un scope específico
     *
     * @param  string  $scope  Scope a verificar (ej: 'users.read')
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        // Si no tiene scopes definidos, tiene acceso total
        if (empty($scopes)) {
            return true;
        }

        // Verificar scope wildcard
        if (in_array('*', $scopes)) {
            return true;
        }

        // Verificar scope específico
        return in_array($scope, $scopes);
    }

    /**
     * Verificar si la key tiene alguno de los scopes especificados
     *
     * @param  array<string>  $scopes  Array de scopes a verificar
     */
    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si la key tiene todos los scopes especificados
     *
     * @param  array<string>  $scopes  Array de scopes a verificar
     */
    public function hasAllScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (! $this->hasScope($scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Actualizar último uso
     */
    public function updateLastUsed(): bool
    {
        return $this->update([
            'last_used_at' => now(),
        ]);
    }
}
