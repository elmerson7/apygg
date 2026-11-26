<?php

namespace App\Console\Commands;

use App\Contracts\FileScanServiceInterface;
use App\Enums\FileScanStatus;
use Illuminate\Console\Command;

/**
 * Vuelve a encolar el escaneo antivirus (docs/files.md, "Escaneo antivirus"): los `failed` tras una caída de clamd,
 * los `pending` que se quedaron sin job o, con `--status=skipped`, lo subido antes de encender el escaneo.
 *
 * Uso: php artisan files:scan [--status=pending,failed] [--limit=1000]
 */
class FilesScanCommand extends Command
{
    protected $signature = 'files:scan
        {--status=pending,failed : Estados a reencolar (pending, failed, skipped), separados por comas}
        {--limit=1000 : Máximo de archivos por ejecución}';

    protected $description = 'Volver a encolar el escaneo antivirus de los archivos pendientes, fallidos o sin escanear';

    public function handle(FileScanServiceInterface $scans): int
    {
        if (! $scans->enabled()) {
            $this->warn('El escaneo antivirus está apagado (FILE_SCAN_ENABLED=false): no se encola nada.');

            return self::SUCCESS;
        }

        $statuses = [];

        $option = $this->option('status');

        foreach (array_filter(array_map('trim', explode(',', is_string($option) ? $option : ''))) as $value) {
            $status = FileScanStatus::tryFrom($value);

            if (! in_array($status, [FileScanStatus::Pending, FileScanStatus::Failed, FileScanStatus::Skipped], true)) {
                $this->error("Estado no válido: {$value} (usa pending, failed o skipped).");

                return self::INVALID;
            }

            $statuses[] = $status;
        }

        $this->info('Encolados '.$scans->requeue($statuses, max(1, (int) $this->option('limit'))).' escaneos antivirus.');

        return self::SUCCESS;
    }
}
