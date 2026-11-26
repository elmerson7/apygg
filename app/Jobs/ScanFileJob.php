<?php

namespace App\Jobs;

use App\Contracts\FileScanServiceInterface;
use App\Models\File;
use App\Support\Tenancy\TenantQuery;
use Throwable;

/**
 * Escanea un archivo recién subido con el antivirus (docs/files.md, "Escaneo antivirus"). Se encola al crear el archivo
 * con el escaneo encendido (después del commit). Reintenta los fallos pasajeros (clamd caído, límite de VirusTotal);
 * agotados los intentos el archivo queda `failed`, en cuarentena, y `files:scan` lo puede volver a encolar.
 * Corre en el tenant del archivo (`RestoreTenantForJob`).
 */
class ScanFileJob extends Job
{
    public function __construct(public int $fileId)
    {
        parent::__construct();

        $this->tries = max(1, (int) config('files.scanning.tries', 5));
        $this->backoff = [30, 60, 120, 300];
        $this->timeout = (int) config('files.scanning.job_timeout', 300);
        $this->afterCommit();
        $this->onQueue((string) config('files.scanning.queue', 'default'));
    }

    public function process(FileScanServiceInterface $scans): void
    {
        $file = File::query()->find($this->fileId);

        if ($file instanceof File) {
            $scans->scan($file);
        }
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);

        // failed() puede correr fuera del tenant del job: se busca por id en modo plataforma (solo esta fila).
        TenantQuery::withoutTenant(function () use ($exception) {
            $file = File::query()->find($this->fileId);

            if ($file instanceof File) {
                app(FileScanServiceInterface::class)->markFailed($file, mb_substr($exception->getMessage(), 0, 255));
            }
        }, 'marcar como fallido el escaneo antivirus de un archivo');
    }
}
