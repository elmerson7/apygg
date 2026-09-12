<?php

namespace App\Console\Commands;

use App\Models\Logs\LlmAuditLog;
use Illuminate\Console\Command;

/**
 * LlmPruneLogsCommand
 *
 * Elimina registros antiguos de la auditoría de IA. La tabla guarda prompts y
 * respuestas truncados, así que crece rápido en proyectos que usan IA de forma
 * intensiva.
 *
 * Uso:
 *   php artisan llm:prune-logs
 *   php artisan llm:prune-logs --days=30 --dry-run
 */
class LlmPruneLogsCommand extends Command
{
    protected $signature = 'llm:prune-logs
                            {--days= : Antigüedad en días (por defecto, config llm.logs_retention_days)}
                            {--dry-run : Mostrar cuántos se borrarían sin borrar nada}';

    protected $description = 'Eliminar registros antiguos de la auditoría de IA';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('llm.logs_retention_days', 90));

        if ($days < 1) {
            $this->error('El número de días debe ser mayor que 0.');

            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $count = LlmAuditLog::where('created_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info("No hay registros anteriores a {$cutoff->format('Y-m-d')}.");

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: se borrarían {$count} registros anteriores a {$cutoff->format('Y-m-d')}.");

            return Command::SUCCESS;
        }

        // Por lotes, para no bloquear la tabla en instalaciones grandes.
        $deleted = 0;
        while (true) {
            $batch = LlmAuditLog::where('created_at', '<', $cutoff)->limit(1000)->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += LlmAuditLog::whereIn('id', $batch)->delete();
        }

        $this->info("✓ Eliminados {$deleted} registros anteriores a {$cutoff->format('Y-m-d')}.");

        return Command::SUCCESS;
    }
}
