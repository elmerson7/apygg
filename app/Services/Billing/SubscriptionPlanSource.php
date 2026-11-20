<?php

namespace App\Services\Billing;

use App\Contracts\SubscriptionPlanSourceInterface;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Plan de la suscripción con derecho a uso (en prueba, activa o en gracia por impago); manda sobre `tenants.plan`.
 */
class SubscriptionPlanSource implements SubscriptionPlanSourceInterface
{
    public function planCodeFor(int $tenantId): ?string
    {
        $plan = DB::table('subscriptions')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', SubscriptionStatus::accessValues())
            ->whereNull('deleted_at')
            ->value('plan_code');

        return is_string($plan) ? $plan : null;
    }
}
