<?php

namespace App\Console\Commands;

use App\Support\Tenancy\TenancyCoherence;
use Illuminate\Console\Command;

/**
 * Comprueba que `MULTI_TENANCY_ENABLED` es coherente con los datos (sale con error si no). Útil en despliegues y CI.
 */
class TenancyCheckCommand extends Command
{
    protected $signature = 'tenancy:check';

    protected $description = 'Comprueba que la bandera de multiempresa es coherente con las empresas existentes';

    public function handle(): int
    {
        $problems = TenancyCoherence::problems();

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems === []) {
            $this->info('Multiempresa coherente.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
