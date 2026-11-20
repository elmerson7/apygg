<?php

namespace App\Console\Commands;

use App\Contracts\MaintenanceServiceInterface;
use Illuminate\Console\Command;

/**
 * Desactiva el modo mantenimiento de la API. Uso: php artisan api:up
 */
class MaintenanceDisableCommand extends Command
{
    protected $signature = 'api:up';

    protected $description = 'Desactivar el modo mantenimiento de la API';

    public function handle(MaintenanceServiceInterface $maintenance): int
    {
        $maintenance->disable();

        if ($maintenance->state() !== null) {
            $this->error('Sigue activo por MAINTENANCE_ENABLED=true (entorno): quítalo y reinicia.');

            return self::FAILURE;
        }

        $this->info('API operativa.');

        return self::SUCCESS;
    }
}
