<?php

namespace App\Services\Billing;

use App\Contracts\PlanCatalogInterface;
use App\Enums\BillingInterval;
use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo declarativo (config/billing.php) copiado a BD para poder referenciarlo desde suscripciones y facturas.
 * Se eligió config + BD (y no solo BD con CRUD) porque el catálogo cambia con un deploy revisado, no con un clic:
 * `billing:sync-plans` es idempotente y un precio editado no altera lo que ya paga cada suscripción (importe congelado).
 */
class PlanCatalog implements PlanCatalogInterface
{
    public function publicPlans(): Collection
    {
        return Plan::query()
            ->where('is_public', true)
            ->where('is_active', true)
            ->with(['prices' => fn ($q) => $q->where('is_active', true)->orderBy('currency')->orderBy('interval')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findPrice(string $planCode, string $currency, BillingInterval $interval): PlanPrice
    {
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->first();

        if (! $plan instanceof Plan) {
            throw BillingException::planNotFound();
        }

        $price = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('currency', strtoupper($currency))
            ->where('interval', $interval->value)
            ->where('is_active', true)
            ->first();

        if (! $price instanceof PlanPrice) {
            throw BillingException::currencyUnavailable();
        }

        $price->setRelation('plan', $plan);

        return $price;
    }

    public function sync(): array
    {
        $summary = ['plans_created' => 0, 'plans_updated' => 0, 'plans_deactivated' => 0, 'prices_upserted' => 0, 'prices_deactivated' => 0];

        DB::transaction(function () use (&$summary): void {
            $declared = (array) config('billing.plans', []);

            foreach ($declared as $code => $definition) {
                $code = (string) $code;
                $definition = (array) $definition;

                $plan = Plan::query()->where('code', $code)->first() ?? new Plan(['code' => $code]);
                $existed = $plan->exists;

                $plan->fill([
                    'name' => (string) (config("plans.plans.{$code}.name") ?? ucfirst($code)),
                    'description' => $definition['description'] ?? null,
                    'is_public' => (bool) ($definition['public'] ?? true),
                    'is_active' => true,
                    'trial_days' => (int) ($definition['trial_days'] ?? 0),
                    'sort_order' => (int) ($definition['sort'] ?? 0),
                ])->save();

                $existed ? $summary['plans_updated']++ : $summary['plans_created']++;

                $keep = [];

                foreach ((array) ($definition['prices'] ?? []) as $currency => $intervals) {
                    foreach ((array) $intervals as $interval => $amountMinor) {
                        $currency = strtoupper((string) $currency);
                        $interval = BillingInterval::from((string) $interval);
                        $amount = Money::of((int) $amountMinor, $currency);   // valida la moneda y el signo

                        PlanPrice::query()->updateOrCreate(
                            ['plan_id' => $plan->id, 'currency' => $currency, 'interval' => $interval->value],
                            ['unit_amount' => $amount, 'is_active' => true],
                        );

                        $keep[] = $currency.'|'.$interval->value;
                        $summary['prices_upserted']++;
                    }
                }

                $stale = PlanPrice::query()->where('plan_id', $plan->id)->where('is_active', true)->get()
                    ->filter(fn (PlanPrice $p) => ! in_array($p->currency.'|'.$p->interval->value, $keep, true));

                foreach ($stale as $price) {
                    $price->forceFill(['is_active' => false])->save();
                    $summary['prices_deactivated']++;
                }
            }

            foreach (Plan::query()->where('is_active', true)->whereNotIn('code', array_map('strval', array_keys($declared)))->get() as $plan) {
                $plan->forceFill(['is_active' => false])->save();
                $summary['plans_deactivated']++;
            }
        });

        return $summary;
    }
}
