<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Override de un límite del plan para un tenant. `value` NULL = ilimitado.
 * Es de plataforma: `tenant_id` es el destino del override, no un aislamiento.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property int|null $value
 */
class TenantLimitOverride extends EloquentModel
{
    protected $table = 'tenant_limit_overrides';

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected $casts = [
        'tenant_id' => 'integer',
        'value' => 'integer',
    ];
}
