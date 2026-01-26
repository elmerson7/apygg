<?php

namespace App\Models\Logs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SecurityLog Model
 *
 * Modelo para registrar eventos de seguridad del sistema.
 * Usa ID auto-incrementable como primary key (no UUID) según estrategia del proyecto.
 */
class SecurityLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'logs_security';

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return \Database\Factories\SecurityLogFactory::new();
    }

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'trace_id',
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'details',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tipos de eventos de seguridad
     */
    public const EVENT_LOGIN_SUCCESS = 'login_success';

    public const EVENT_LOGIN_FAILURE = 'login_failure';

    public const EVENT_PERMISSION_DENIED = 'permission_denied';

    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';

    public const EVENT_PASSWORD_CHANGED = 'password_changed';

    public const EVENT_TOKEN_REVOKED = 'token_revoked';

    public const EVENT_ACCOUNT_LOCKED = 'account_locked';

    public const EVENT_ACCOUNT_UNLOCKED = 'account_unlocked';

    public const EVENT_API_KEY_USED = 'api_key_used';

    /**
     * Relación con User (opcional, puede ser null)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Relación con ApiLog a través de trace_id
     */
    public function apiLog(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Logs\ApiLog::class, 'trace_id', 'trace_id');
    }

    /**
     * Scope para filtrar por trace_id
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTraceId($query, string $traceId)
    {
        return $query->where('trace_id', $traceId);
    }

    /**
     * Scope para filtrar por usuario
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUserId($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar por tipo de evento
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope para filtrar intentos de login fallidos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLoginFailures($query)
    {
        return $query->where('event_type', self::EVENT_LOGIN_FAILURE);
    }

    /**
     * Scope para filtrar actividades sospechosas
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuspiciousActivity($query)
    {
        return $query->where('event_type', self::EVENT_SUSPICIOUS_ACTIVITY);
    }

    /**
     * Scope para filtrar por IP
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByIpAddress($query, string $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope para filtrar por rango de fechas
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, ?string $startDate = null, ?string $endDate = null)
    {
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Scope para ordenar por más recientes
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Verificar si el evento es crítico
     */
    public function isCritical(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_LOGIN_FAILURE,
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::EVENT_ACCOUNT_LOCKED,
        ]);
    }
}
