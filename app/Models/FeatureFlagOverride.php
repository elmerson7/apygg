<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Override de una flag para UN tenant o UN usuario (exactamente uno; lo garantiza un CHECK en BD).
 * Es de plataforma: `tenant_id` aquí es el DESTINO del override, no el contexto de aislamiento.
 *
 * @property int $id
 * @property string $feature_key
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property bool $enabled
 */
class FeatureFlagOverride extends EloquentModel
{
    protected $table = 'feature_flag_overrides';

    protected $fillable = ['feature_key', 'tenant_id', 'user_id', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
