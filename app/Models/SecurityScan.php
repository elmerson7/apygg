<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SecurityScan Model
 *
 * Un escaneo de seguridad: los hallazgos recolectados, su versión anonimizada
 * y el análisis del LLM.
 *
 * Cada escaneo lleva su propia `salt`. Los identificadores sensibles (IPs,
 * correos, ids) se hashean con ella antes de salir hacia el modelo, así que
 * dos escaneos producen hashes distintos para el mismo dato: se pueden
 * correlacionar eventos dentro de un escaneo sin construir un identificador
 * estable que persiga a una persona entre escaneos.
 *
 * @property int $id
 * @property string $status
 * @property array<int, array<string, mixed>>|null $findings
 * @property array<string, mixed>|null $analysis
 * @property string $salt
 */
class SecurityScan extends Model
{
    protected $table = 'security_scans';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STOPPED = 'stopped';

    /** @var array<int, string> */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    protected $fillable = [
        'created_by',
        'status',
        'llm_model',
        'findings',
        'analysis',
        'salt',
        'stopped_by',
        'started_at',
        'finished_at',
        'error',
    ];

    protected $casts = [
        'findings' => 'array',
        'analysis' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * La sal nunca se expone: publicarla permitiría deshacer la anonimización
     * por fuerza bruta sobre un espacio pequeño (una IP son 2^32 candidatos).
     *
     * @var array<int, string>
     */
    protected $hidden = ['salt'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stoppedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public static function generateSalt(): string
    {
        return Str::random(40);
    }
}
