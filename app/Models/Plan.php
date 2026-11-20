<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan del catálogo (global de la plataforma; sin `tenant_id`). Se declara en `config/billing.php` y se
 * sincroniza con `billing:sync-plans`. Los límites del plan viven en `config/plans.php` bajo el mismo `code`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_public
 * @property bool $is_active
 * @property int $trial_days
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_public', 'is_active', 'trial_days', 'sort_order', 'metadata'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_active' => 'boolean', 'trial_days' => 'integer', 'sort_order' => 'integer', 'metadata' => 'array'];
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }
}
