<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\BaseResource;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'status' => $s->status->value,
            'plan_code' => $s->plan_code,
            'plan_name' => $s->relationLoaded('plan') ? $s->plan->name : null,
            'interval' => $s->interval->value,
            'currency' => $s->currency,
            'unit_amount_minor' => $s->unit_amount->amountMinor,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_start' => $s->current_period_start->toIso8601String(),
            'current_period_end' => $s->current_period_end->toIso8601String(),
            'cancel_at_period_end' => $s->cancel_at_period_end,
            'canceled_at' => $s->canceled_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'scheduled_plan_price_id' => $s->scheduled_plan_price_id,
            'renewal_attempts' => $s->renewal_attempts,
            'next_retry_at' => $s->next_retry_at?->toIso8601String(),
            'last_failure_code' => $s->last_failure_code,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
