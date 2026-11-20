<?php

namespace App\Console\Commands;

use App\Contracts\MaintenanceServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Activa el modo mantenimiento de la API (estado en la caché compartida, no en un archivo).
 *
 * Uso: php artisan api:down [--message="..."] [--until=2026-01-01T10:00:00Z]
 */
class MaintenanceEnableCommand extends Command
{
    protected $signature = 'api:down {--message= : Mensaje para los clientes} {--until= : Fin estimado (fecha ISO 8601)}';

    protected $description = 'Activar el modo mantenimiento de la API (503 compartido entre réplicas)';

    public function handle(MaintenanceServiceInterface $maintenance): int
    {
        $until = null;

        if (is_string($this->option('until')) && $this->option('until') !== '') {
            try {
                $until = Carbon::parse($this->option('until'));
            } catch (\Throwable) {
                $this->error('--until no es una fecha válida.');

                return self::FAILURE;
            }
        }

        $message = $this->option('message');
        $maintenance->enable(is_string($message) && $message !== '' ? $message : null, $until);
        $this->warn('API en mantenimiento.');

        return self::SUCCESS;
    }
}
