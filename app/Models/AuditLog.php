<?php

namespace App\Models;

use App\Exceptions\AuditException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Una entrada de auditoría (docs/audit.md). Inmutable: no se actualiza ni se borra desde la aplicación
 * (la retención usa `audit:prune`, que borra por consulta). NO usa `BelongsToTenant`: la plataforma ve
 * todas y los endpoints de tenant filtran SIEMPRE por el tenant activo en `AuditService`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $event
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $request_id
 * @property CarbonInterface $created_at
 */
class AuditLog extends EloquentModel
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw AuditException::immutable());
        static::deleting(fn () => throw AuditException::immutable());
    }
}
