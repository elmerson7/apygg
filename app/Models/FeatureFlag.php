<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado en BD de una flag declarada en `config/features.php` (docs/settings-and-flags.md).
 * Es de plataforma: no usa `BelongsToTenant` ni borrado lógico.
 *
 * @property int $id
 * @property string $key
 * @property bool $enabled
 * @property int $rollout_percentage
 * @property string $rollout_by
 * @property string|null $description
 */
class FeatureFlag extends EloquentModel
{
    protected $table = 'feature_flags';

    protected $fillable = ['key', 'enabled', 'rollout_percentage', 'rollout_by', 'description'];

    protected $casts = [
        'enabled' => 'boolean',
        'rollout_percentage' => 'integer',
    ];

    /**
     * Overrides de esta flag (por tenant o por usuario).
     *
     * @return HasMany<FeatureFlagOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class, 'feature_key', 'key');
    }
}
