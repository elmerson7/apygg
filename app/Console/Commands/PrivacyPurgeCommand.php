<?php

namespace App\Console\Commands;

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\PersonalDataExportServiceInterface;
use Illuminate\Console\Command;

/**
 * Retención de lo que genera el módulo de privacidad: caduca exportaciones (borra el ZIP) y purga registros
 * antiguos. Los plazos están en `config/privacy.php`. El resto de purgados (auditoría, notificaciones, logs,
 * correo) ya tiene su comando: ver docs/privacy.md.
 *
 * Uso: php artisan privacy:purge
 */
class PrivacyPurgeCommand extends Command
{
    protected $signature = 'privacy:purge';

    protected $description = 'Caducar exportaciones de datos personales y purgar solicitudes antiguas';

    public function handle(PersonalDataExportServiceInterface $exports, AccountDeletionServiceInterface $deletions): int
    {
        $this->info('Exportaciones caducadas: '.$exports->expireDue());
        $this->info('Solicitudes de borrado purgadas: '.$deletions->purgeFinished());

        return self::SUCCESS;
    }
}
