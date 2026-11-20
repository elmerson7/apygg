<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Support\Money\Money;
use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suscripción del tenant (docs/billing.md). Plan, intervalo, moneda e importe quedan CONGELADOS al
 * contratar; el estado solo cambia por `SubscriptionLifecycle::transition()` (máquina de `SubscriptionStatus`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property int $plan_price_id
 * @property string $plan_code
 * @property SubscriptionStatus $status
 * @property BillingInterval $interval
 * @property string $currency
 * @property Money $unit_amount
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface $billing_anchor_at
 * @property CarbonInterface $current_period_start
 * @property CarbonInterface $current_period_end
 * @property bool $cancel_at_period_end
 * @property CarbonInterface|null $canceled_at
 * @property CarbonInterface|null $ended_at
 * @property int|null $scheduled_plan_price_id
 * @property int $renewal_attempts
 * @property CarbonInterface|null $next_retry_at
 * @property string|null $last_failure_code
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'plan_id', 'plan_price_id', 'plan_code', 'status', 'interval', 'currency', 'unit_amount', 'trial_ends_at',
        'billing_anchor_at', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'canceled_at',
        'ended_at', 'scheduled_plan_price_id', 'renewal_attempts', 'next_retry_at', 'last_failure_code',
    ];

    /**
     * Red de seguridad de la máquina de estados: ningún camino (servicio, comando, consola) puede guardar una transición
     * prohibida, aunque no pase por `SubscriptionLifecycle::transition()`.
     */
    protected static function booted(): void
    {
        static::updating(function (Subscription $subscription): void {
            $raw = $subscription->getRawOriginal('status');

            if (! $subscription->isDirty('status') || ! is_string($raw)) {
                return;
            }

            $from = SubscriptionStatus::from($raw);

            if (! $from->canTransitionTo($subscription->status)) {
                throw BillingException::invalidTransition($from, $subscription->status);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'unit_amount' => MoneyCast::class,
            'trial_ends_at' => 'datetime',
            'billing_anchor_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'renewal_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<PlanPrice, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
