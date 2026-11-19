<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\JwtBlacklistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JwtBlacklist Model
 *
 * Modelo para tokens JWT revocados (blacklist).
 * Almacena tokens que han sido revocados y no deben ser aceptados.
 *
 * @property CarbonInterface|null $expires_at
 */
class JwtBlacklist extends Model
{
    /** @use HasFactory<JwtBlacklistFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'jwt_blacklist';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'jti',
        'user_id',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Verificar si el token ha expirado
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
