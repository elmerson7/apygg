<?php

namespace App\Console\Commands;

use App\Contracts\DirectUploadServiceInterface;
use Illuminate\Console\Command;

/**
 * Limpia las intenciones de subida directa caducadas (borra su objeto huérfano y libera la reserva
 * de cuota ya vencida) y las cerradas más viejas que `files.direct_upload.retention_days`.
 *
 * Uso: php artisan files:purge-intents
 */
class FilesPurgeIntentsCommand extends Command
{
    protected $signature = 'files:purge-intents';

    protected $description = 'Eliminar las intenciones de subida directa caducadas y sus objetos huérfanos';

    public function handle(DirectUploadServiceInterface $uploads): int
    {
        $this->info('Eliminadas '.$uploads->purgeExpired().' intenciones de subida.');

        return self::SUCCESS;
    }
}
