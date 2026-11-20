<?php

namespace App\Console\Commands;

use App\Contracts\AuditServiceInterface;
use Illuminate\Console\Command;

/**
 * Borra la auditoría anterior a la retención (`audit.retention_days`).
 *
 * Uso: php artisan audit:prune [--days=N] [--dry-run]
 */
class AuditPruneCommand extends Command
{
    protected $signature = 'audit:prune {--days= : Días a conservar (por defecto audit.retention_days)} {--dry-run : Mostrar cuántas se borrarían sin borrar nada}';

    protected $description = 'Eliminar las entradas de auditoría más antiguas que la retención configurada';

    public function handle(AuditServiceInterface $audit): int
    {
        $days = $this->option('days') !== null ? max(0, (int) $this->option('days')) : null;
        $count = $audit->prune($days, (bool) $this->option('dry-run'));

        $this->option('dry-run')
            ? $this->warn("DRY-RUN: se borrarían {$count} entradas de auditoría.")
            : $this->info("Eliminadas {$count} entradas de auditoría.");

        return self::SUCCESS;
    }
}
