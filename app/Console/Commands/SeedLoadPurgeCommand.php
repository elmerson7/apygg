<?php

namespace App\Console\Commands;

use App\Support\SeedLoad\LoadDataPurger;
use Illuminate\Console\Command;

/**
 * Inverso de `seed:load`: borra solo lo que ese comando generó (docs/seed-load.md).
 */
class SeedLoadPurgeCommand extends Command
{
    protected $signature = 'seed:load:purge {--force : No pedir confirmación}';

    protected $description = 'Borra los datos generados por seed:load (empresas ld-*, usuarios @seed-load.test) y deja la numeración de facturas sin huecos';

    public function handle(LoadDataPurger $purger): int
    {
        if (app()->environment('production')) {
            $this->error('seed:load:purge no se ejecuta en producción.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('¿Borrar todos los datos generados por seed:load?')) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $result = $purger->run();

        $this->info(sprintf('Borrados: %d empresas, %d usuarios, %d facturas (%.1f s).', $result['tenants'], $result['users'], $result['invoices'], microtime(true) - $started));

        return self::SUCCESS;
    }
}
