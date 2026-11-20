<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio de un plan por moneda e intervalo. Una suscripción COPIA el importe al contratarse: cambiar este
 * precio no altera lo que ya paga cada cliente.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $currency
 * @property BillingInterval $interval
 * @property Money $unit_amount
 * @property bool $is_active
 * @property-read Plan $plan
 */
class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'currency', 'interval', 'unit_amount', 'is_active'];

    protected function casts(): array
    {
        return ['interval' => BillingInterval::class, 'unit_amount' => MoneyCast::class, 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
