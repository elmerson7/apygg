<?php

namespace App\Services\Files;

use App\Contracts\FileScanServiceInterface;
use App\Contracts\MalwareScannerInterface;
use App\Enums\FileScanStatus;
use App\Events\FileInfected;
use App\Exceptions\FileNotAvailableException;
use App\Jobs\ScanFileJob;
use App\Models\File;
use App\Models\Tenant;
use App\Services\LogService;
use App\Support\Files\ScanVerdict;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\Storage;

/**
 * Escaneo antivirus de las subidas (docs/files.md, "Escaneo antivirus"). Los motores (`files.scanning.drivers`) se
 * pasan en orden; el primero que detecta malware decide. El cambio de estado es condicional (solo desde `pending` o
 * `failed`): dos escaneos simultáneos del mismo archivo no se pisan.
 */
class FileScanService implements FileScanServiceInterface
{
    private const BYTES_PER_MB = 1048576;

    /**
     * @param  list<MalwareScannerInterface>  $scanners
     */
    public function __construct(
        private readonly array $scanners,
        private readonly TenantContext $context,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('files.scanning.enabled', false) && $this->scanners !== [];
    }

    public function initialStatus(): FileScanStatus
    {
        return $this->enabled() ? FileScanStatus::Pending : FileScanStatus::Skipped;
    }

    public function scan(File $file): FileScanStatus
    {
        if (! in_array($file->scan_status, [FileScanStatus::Pending, FileScanStatus::Failed], true)) {
            return $file->scan_status;
        }

        if ($this->scanners === []) {
            return $this->record($file, FileScanStatus::Failed, 'sin motores antivirus configurados');
        }

        $maxBytes = (int) config('files.scanning.max_size_mb', 100) * self::BYTES_PER_MB;

        if ($file->size > $maxBytes) {
            return config('files.scanning.oversize', 'block') === 'allow'
                ? $this->record($file, FileScanStatus::Clean, 'sin escanear: supera files.scanning.max_size_mb')
                : $this->record($file, FileScanStatus::Failed, 'demasiado grande para escanear (files.scanning.max_size_mb)');
        }

        $details = [];

        foreach ($this->scanners as $scanner) {
            $verdict = $this->runScanner($scanner, $file);

            if ($verdict->isInfected()) {
                return $this->quarantineInfected($file, (string) $verdict->detail);
            }

            if ($verdict->isError()) {
                if ($verdict->retryable) {
                    // El job reintenta; el archivo sigue en cuarentena mientras tanto.
                    throw new \RuntimeException("Escaneo antivirus fallido ({$verdict->detail})");
                }

                return $this->record($file, FileScanStatus::Failed, (string) $verdict->detail);
            }

            if ($verdict->detail !== null) {
                $details[] = $verdict->detail;
            }
        }

        return $this->record($file, FileScanStatus::Clean, $details === [] ? null : implode('; ', $details));
    }

    public function requeue(array $statuses, int $limit): int
    {
        $values = array_map(fn (FileScanStatus $s): string => $s->value, $statuses);

        if ($values === [] || $limit < 1 || ! $this->enabled()) {
            return 0;
        }

        /** @var list<array{id: int, tenant_id: int|null}> $rows */
        $rows = TenantQuery::withoutTenant(fn (): array => File::query()
            ->whereIn('scan_status', $values)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'tenant_id'])
            ->map(fn (File $f): array => ['id' => (int) $f->getKey(), 'tenant_id' => $f->getAttribute('tenant_id') === null ? null : (int) $f->getAttribute('tenant_id')])
            ->all(), 'files: volver a encolar escaneos antivirus');

        $queued = 0;
        $tenants = [];

        foreach ($rows as $row) {
            $tenantId = $row['tenant_id'];
            $tenant = null;

            if ($tenantId !== null && $this->context->enabled()) {
                $tenant = $tenants[$tenantId] ??= Tenant::query()->find($tenantId);

                // Un tenant borrado no tiene a quién escanear; uno suspendido falla el job (RestoreTenantForJob).
                if (! $tenant instanceof Tenant) {
                    continue;
                }
            }

            $dispatch = function () use ($row): void {
                // `skipped` pasa a `pending` (cuarentena) antes de encolar: scan() solo trabaja sobre pending/failed.
                File::query()->whereKey($row['id'])
                    ->where('scan_status', FileScanStatus::Skipped->value)
                    ->update(['scan_status' => FileScanStatus::Pending->value]);

                ScanFileJob::dispatch($row['id']);
            };

            $tenant instanceof Tenant ? $this->context->run($tenant, $dispatch) : TenantQuery::withoutTenant($dispatch, 'files: escaneo sin tenant');
            $queued++;
        }

        return $queued;
    }

    public function markFailed(File $file, string $reason): void
    {
        if ($file->scan_status === FileScanStatus::Pending) {
            $this->record($file, FileScanStatus::Failed, $reason);
        }
    }

    public function assertAvailable(File $file): void
    {
        if ($file->isAvailable()) {
            return;
        }

        match ($file->scan_status) {
            FileScanStatus::Skipped, FileScanStatus::Clean => null,
            FileScanStatus::Pending => throw FileNotAvailableException::pending(),
            FileScanStatus::Failed => throw FileNotAvailableException::failed(),
            FileScanStatus::Infected => throw FileNotAvailableException::infected(),
        };
    }

    private function runScanner(MalwareScannerInterface $scanner, File $file): ScanVerdict
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);

        if (! is_resource($stream)) {
            return ScanVerdict::error('el contenido no está en el almacenamiento');
        }

        try {
            return $scanner->scan($stream, (int) $file->size);
        } finally {
            fclose($stream); // el motor no lo cierra (contrato de MalwareScannerInterface)
        }
    }

    private function quarantineInfected(File $file, string $signature): FileScanStatus
    {
        // Primero se borra el contenido: si el borrado falla, el archivo sigue en cuarentena y el job reintenta.
        $disk = Storage::disk($file->disk);

        if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
            throw new \RuntimeException('No se pudo borrar el contenido infectado');
        }

        $status = $this->record($file, FileScanStatus::Infected, $signature, ['url' => null]);

        if ($status === FileScanStatus::Infected) {
            LogService::warning('Malware detectado en un archivo subido', [
                'file_id' => $file->id,
                'user_id' => $file->user_id,
                'signature' => $signature,
            ], 'security');

            FileInfected::dispatch($file);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(File $file, FileScanStatus $status, ?string $result, array $extra = []): FileScanStatus
    {
        $values = ['scan_status' => $status->value, 'scanned_at' => now(), 'scan_result' => $result === null ? null : mb_substr($result, 0, 255)] + $extra;

        $updated = File::query()
            ->whereKey($file->getKey())
            ->whereIn('scan_status', [FileScanStatus::Pending->value, FileScanStatus::Failed->value])
            ->update($values);

        if ($updated === 0) {
            // Otro escaneo terminó antes: manda su resultado.
            $file->refresh();

            return $file->scan_status;
        }

        $file->forceFill($values)->syncOriginal();

        LogService::info('Archivo escaneado', ['file_id' => $file->id, 'scan_status' => $status->value, 'scan_result' => $result], 'files');

        return $status;
    }
}
