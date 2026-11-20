<?php

namespace App\Support\Tenancy;

use App\Contracts\SubscriptionAccessInterface;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Política de acceso por defecto cuando no hay módulo de facturación: acceso completo, sin avisos.
 * El módulo `billing` la sustituye con su `SubscriptionAccess` (binding en ContractServiceProvider).
 */
final class NullSubscriptionAccess implements SubscriptionAccessInterface
{
    public function stateFor(int $tenantId): array
    {
        return [
            'mode' => self::MODE_FULL,
            'status' => null,
            'code' => null,
            'grace_ends_at' => null,
            'invoice_id' => null,
            'suggested_action' => null,
        ];
    }

    public function enforce(Request $request, Tenant $tenant): ?string
    {
        return null;
    }
}
