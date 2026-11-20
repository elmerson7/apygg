<?php

namespace App\Contracts;

use App\Integrations\Exceptions\IntegrationException;
use App\Models\BillingAccount;
use App\Models\Tenant;
use App\Models\User;

/**
 * Cliente y método de pago del tenant en la pasarela (docs/billing.md §Método de pago). Nunca guarda datos de
 * tarjeta: solo el id que devuelve la pasarela y marca/últimos 4.
 */
interface BillingAccountServiceInterface
{
    /** Cuenta del tenant, si existe. Exige `tenant-billing.read` del actor en ese tenant. */
    public function show(Tenant $tenant, User $actor): ?BillingAccount;

    /**
     * Guarda el método de pago por defecto a partir del token de la pasarela. Exige `tenant-billing.manage`.
     *
     * @throws IntegrationException si la pasarela lo rechaza
     */
    public function setPaymentMethod(Tenant $tenant, User $actor, string $token): BillingAccount;

    /** Igual, sin comprobar al actor (uso interno, al contratar con token). */
    public function attach(Tenant $tenant, string $token): BillingAccount;
}
