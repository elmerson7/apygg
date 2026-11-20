<?php

namespace App\Console\Commands;

use App\Contracts\AccountDeletionServiceInterface;
use App\Jobs\ExecuteAccountDeletionJob;
use Illuminate\Console\Command;

/**
 * Encola la ejecución de los borrados de cuenta cuyo periodo de gracia venció. Programado cada hora; es
 * idempotente (el job reclama la solicitud bajo bloqueo).
 *
 * Uso: php artisan privacy:process-deletions [--sync]
 */
class PrivacyProcessDeletionsCommand extends Command
{
    protected $signature = 'privacy:process-deletions {--sync : Ejecutar en esta misma petición, sin cola}';

    protected $description = 'Ejecutar los borrados de cuenta vencidos';

    public function handle(AccountDeletionServiceInterface $deletions): int
    {
        $ids = $deletions->dueIds();

        foreach ($ids as $id) {
            $this->option('sync') ? $deletions->execute($id) : ExecuteAccountDeletionJob::dispatch($id);
        }

        $this->info('Borrados vencidos: '.count($ids));

        return self::SUCCESS;
    }
}
