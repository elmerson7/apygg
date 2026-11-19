<?php

namespace App\Jobs;

use App\Contracts\SecurityAuditServiceInterface;
use App\Models\SecurityScan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunSecurityScanJob
 *
 * Ejecuta el escaneo de seguridad en segundo plano: recolecta, anonimiza y
 * analiza. Puede tardar (la llamada al LLM sola ya se va a decenas de
 * segundos), así que no tiene sentido hacerlo en el request.
 *
 * No se reintenta: un escaneo a medias con reintento produciría análisis
 * duplicados sobre la misma ventana temporal.
 */
class RunSecurityScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(private readonly int $scanId)
    {
        $this->timeout = (int) config('queue.security_scan_timeout', 600);
    }

    public function handle(SecurityAuditServiceInterface $service): void
    {
        $scan = SecurityScan::find($this->scanId);

        if (! $scan) {
            Log::warning('RunSecurityScanJob: el escaneo ya no existe', ['scan_id' => $this->scanId]);

            return;
        }

        // Alguien pudo detenerlo mientras estaba en cola.
        if ($scan->status === SecurityScan::STATUS_STOPPED) {
            return;
        }

        $service->run($scan);
    }

    public function failed(\Throwable $exception): void
    {
        SecurityScan::where('id', $this->scanId)->update([
            'status' => SecurityScan::STATUS_FAILED,
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
