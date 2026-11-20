<?php

namespace App\Console\Commands;

use App\Contracts\IdempotencyInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra las claves de idempotencia caducadas (respuestas viejas y reservas de procesos muertos).
 *
 * Uso: php artisan idempotency:clean [--dry-run]
 */
class IdempotencyCleanCommand extends Command
{
    protected $signature = 'idempotency:clean {--dry-run : Mostrar cuántas se borrarían sin borrar nada}';

    protected $description = 'Eliminar las claves de idempotencia caducadas';

    public function handle(IdempotencyInterface $idempotency): int
    {
        if ($this->option('dry-run')) {
            $count = DB::table('idempotency_keys')->where('expires_at', '<=', now())->count();
            $this->warn("DRY-RUN: se borrarían {$count} claves caducadas.");

            return self::SUCCESS;
        }

        $this->info('Eliminadas '.$idempotency->purgeExpired().' claves de idempotencia caducadas.');

        return self::SUCCESS;
    }
}
