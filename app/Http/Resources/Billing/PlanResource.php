<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\BaseResource;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Plan del catálogo con sus precios (importes enteros en unidad menor + moneda, docs/money.md).
 *
 * @mixin Plan
 */
class PlanResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'trial_days' => $plan->trial_days,
            'limits' => (array) config("plans.plans.{$plan->code}.limits", []),
            'prices' => $plan->prices->map(fn ($price) => [
                'currency' => $price->currency,
                'interval' => $price->interval->value,
                'unit_amount_minor' => $price->unit_amount->amountMinor,
            ])->values()->all(),
        ];
    }
}
