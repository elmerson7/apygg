<?php

namespace App\Contracts;

use App\Enums\BillingInterval;
use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Catálogo de planes y precios (docs/billing.md §Catálogo). Global de la plataforma: no depende del tenant.
 */
interface PlanCatalogInterface
{
    /**
     * Planes públicos y activos, con sus precios activos, ordenados (lo que devuelve `GET /plans`).
     *
     * @return Collection<int, Plan>
     */
    public function publicPlans(): Collection;

    /**
     * Precio contratable de un plan (público o privado) por moneda e intervalo.
     *
     * @throws BillingException `plan_not_found` (404) o `price_unavailable` (422)
     */
    public function findPrice(string $planCode, string $currency, BillingInterval $interval): PlanPrice;

    /**
     * Copia `config/billing.php` a las tablas (idempotente). Lo que ya no está en config se desactiva, no se borra
     * (hay suscripciones que lo referencian). Devuelve cuántos planes y precios se crearon, actualizaron o desactivaron.
     *
     * @return array{plans_created: int, plans_updated: int, plans_deactivated: int, prices_upserted: int, prices_deactivated: int}
     */
    public function sync(): array;
}
