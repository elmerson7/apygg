<?php

namespace App\Console\Commands;

use App\Models\Mail\MailLog;
use Illuminate\Console\Command;

/**
 * MailLogsCleanCommand
 *
 * Elimina registros antiguos de `mail_logs` (y en cascada sus eventos).
 * Sin esta limpieza la tabla crece sin límite: es la de mayor volumen del
 * sistema de correo.
 *
 * Uso:
 *   php artisan mail:logs-clean
 *   php artisan mail:logs-clean --days=180 --dry-run
 */
class MailLogsCleanCommand extends Command
{
    protected $signature = 'mail:logs-clean
                            {--days= : Antigüedad en días (por defecto, config mail-system.logs_retention_days)}
                            {--dry-run : Mostrar cuántos se borrarían sin borrar nada}';

    protected $description = 'Eliminar registros antiguos del historial de correo';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('mail-system.logs_retention_days', 90));

        if ($days < 1) {
            $this->error('El número de días debe ser mayor que 0.');

            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = MailLog::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No hay registros anteriores a {$cutoff->format('Y-m-d')}.");

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: se borrarían {$count} registros anteriores a {$cutoff->format('Y-m-d')}.");

            return Command::SUCCESS;
        }

        // Se borra por lotes para no bloquear la tabla en instalaciones grandes.
        $deleted = 0;
        do {
            $batch = MailLog::where('created_at', '<', $cutoff)->limit(1000)->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += MailLog::whereIn('id', $batch)->delete();
        } while (true);

        $this->info("✓ Eliminados {$deleted} registros anteriores a {$cutoff->format('Y-m-d')}.");

        return Command::SUCCESS;
    }
}
