<?php

namespace App\Console\Commands;

use App\Contracts\PlanCatalogInterface;
use Illuminate\Console\Command;

/**
 * Copia el catálogo de `config/billing.php` a las tablas `plans` y `plan_prices` (idempotente).
 *
 * Uso: php artisan billing:sync-plans
 */
class BillingSyncPlansCommand extends Command
{
    protected $signature = 'billing:sync-plans';

    protected $description = 'Sincronizar el catálogo de planes y precios (config/billing.php) con la base de datos';

    public function handle(PlanCatalogInterface $catalog): int
    {
        $r = $catalog->sync();

        $this->info("Planes: {$r['plans_created']} creados, {$r['plans_updated']} actualizados, {$r['plans_deactivated']} desactivados. Precios: {$r['prices_upserted']} sincronizados, {$r['prices_deactivated']} desactivados.");

        return self::SUCCESS;
    }
}
