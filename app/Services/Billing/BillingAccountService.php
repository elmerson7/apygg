<?php

namespace App\Services\Billing;

use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Models\BillingAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;

class BillingAccountService implements BillingAccountServiceInterface
{
    public function __construct(private readonly PaymentGatewayInterface $gateway) {}

    public function show(Tenant $tenant, User $actor): ?BillingAccount
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.read');

        return BillingAccount::query()->first();
    }

    public function setPaymentMethod(Tenant $tenant, User $actor, string $token): BillingAccount
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-billing.manage');

        return $this->attach($tenant, $token);
    }

    public function attach(Tenant $tenant, string $token): BillingAccount
    {
        $customerId = $this->ensureCustomer($tenant);

        // Llamada externa SIN transacción abierta ni bloqueos. El token no se guarda ni se registra.
        $method = $this->gateway->attachPaymentMethod($customerId, $token);

        return DB::transaction(function () use ($method): BillingAccount {
            $account = BillingAccount::query()->lockForUpdate()->firstOrFail();
            $account->forceFill([
                'payment_method_id' => $method->id,
                'payment_method_brand' => $method->brand,
                'payment_method_last4' => $method->last4,
            ])->save();

            return $account;
        });
    }

    /**
     * Garantiza la fila `billing_accounts` del tenant con su cliente en la pasarela. La clave de idempotencia es
     * estable por tenant, así que dos peticiones a la vez obtienen el mismo cliente; la fila la resuelve el
     * índice único (insertOrIgnore) y se actualiza bajo bloqueo.
     */
    private function ensureCustomer(Tenant $tenant): string
    {
        $account = BillingAccount::query()->first();

        if ($account instanceof BillingAccount && $account->gateway === $this->gateway->name() && $account->gateway_customer_id !== null) {
            return $account->gateway_customer_id;
        }

        $customerId = $this->gateway->createCustomer('tenant-'.$tenant->id, null, 'customer-tenant-'.$tenant->id);

        DB::transaction(function () use ($tenant, $customerId): void {
            DB::table('billing_accounts')->insertOrIgnore([
                'tenant_id' => $tenant->id,
                'gateway' => $this->gateway->name(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $account = BillingAccount::query()->lockForUpdate()->firstOrFail();

            if ($account->gateway !== $this->gateway->name() || $account->gateway_customer_id === null) {
                // Otra pasarela (cambio de PAYMENTS_MODE) o primera vez: el método de pago anterior ya no sirve.
                $account->forceFill([
                    'gateway' => $this->gateway->name(),
                    'gateway_customer_id' => $customerId,
                    'payment_method_id' => null,
                    'payment_method_brand' => null,
                    'payment_method_last4' => null,
                ])->save();
            }
        });

        return (string) BillingAccount::query()->firstOrFail()->gateway_customer_id;
    }
}
