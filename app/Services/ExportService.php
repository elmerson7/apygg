<?php

namespace App\Services;

use App\Contracts\ExportServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Exceptions\ExportImport\ExportImportException;
use App\Exports\Exporter;
use App\Jobs\ExportJob;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exportación en streaming: lee por lotes (`lazyById`) y escribe fila a fila con openspout, así la
 * memoria no crece con el tamaño. El archivo queda en el disco privado (con prefijo por tenant vía
 * FileService) y se entrega por URL temporal o por la ruta autenticada de descarga.
 */
class ExportService implements ExportServiceInterface
{
    private const MIMES = [
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly FileServiceInterface $files,
        private readonly TenantContext $tenant,
    ) {}

    public function request(User $user, string $exporterKey, string $format, array $filters = []): DataExport
    {
        $exporter = $this->exporter($exporterKey);

        if (! $user->can($exporter->permission())) {
            throw new AuthorizationException;
        }

        if (! in_array($format, (array) config('export.formats'), true)) {
            throw ExportImportException::invalidFile("Formato '{$format}' no admitido.");
        }

        $tenantId = $this->tenantId();

        if ($exporter->requiresTenant() && $tenantId === null) {
            throw ExportImportException::tenantRequired();
        }

        $validated = $exporter->filterRules() === []
            ? []
            : Validator::make($filters, $exporter->filterRules())->validate();

        $export = DataExport::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->getKey(),
            'exporter' => $exporterKey,
            'format' => $format,
            'filters' => $validated,
            'status' => DataExport::STATUS_PENDING,
        ]);

        ExportJob::dispatch((int) $export->getKey());

        return $export;
    }

    public function run(DataExport $export): void
    {
        if (! $export->claim()) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'exp_');

        try {
            $exporter = $this->exporter($export->exporter);
            $user = User::query()->find($export->user_id);
            $locale = $user?->locale ?: app()->getLocale();

            $writer = new SpreadsheetWriter($tmp, $export->format);
            $writer->write(array_map(
                fn ($c) => __($c->header, [], $locale),
                $exporter->columns(),
            ));

            $rows = 0;
            $query = $exporter->query($export->filters ?? [], $export->tenant_id);

            foreach ($query->lazyById((int) config('export.chunk_size', 1000), $exporter->idColumn(), $exporter->idAlias()) as $model) {
                $writer->write($exporter->row($model));
                $rows++;
            }

            $writer->close();

            $filename = Str::ulid().'.'.$export->format;
            $disk = $this->files->diskFor(false);
            $stored = $this->files->upload(
                new UploadedFile($tmp, $filename, self::MIMES[$export->format], null, true),
                'exports',
                $filename,
                $disk,
                false,
            );

            $export->forceFill([
                'status' => DataExport::STATUS_COMPLETED,
                'total_rows' => $rows,
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'filename' => $exporter->key().'-'.now()->format('Ymd-His').'.'.$export->format,
                'size' => $stored['size'],
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('export.retention_days', 7)),
                'error' => null,
            ])->save();

            $this->notify($export, $user);
        } catch (Throwable $e) {
            LogService::error('Exportación fallida', ['export_id' => $export->getKey(), 'error' => $e->getMessage()]);
            $this->markFailed($export, class_basename($e).': '.$e->getMessage());
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function paginateFor(User $user, int $perPage): LengthAwarePaginator
    {
        return DataExport::query()
            ->visibleTo((int) $user->getKey(), $this->tenantId())
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findFor(User $user, int $id): DataExport
    {
        return DataExport::query()->visibleTo((int) $user->getKey(), $this->tenantId())->findOrFail($id);
    }

    public function temporaryUrl(DataExport $export): ?string
    {
        if (! $export->isCompleted() || $export->path === null) {
            return null;
        }

        return $this->files->getTemporaryUrl($export->path, $export->disk, (int) config('export.url_minutes', 30));
    }

    public function download(DataExport $export): StreamedResponse
    {
        if (! $export->isCompleted()) {
            throw ExportImportException::notReady();
        }

        if ($export->path === null || ! Storage::disk((string) $export->disk)->exists($export->path)) {
            throw ExportImportException::fileMissing();
        }

        return Storage::disk((string) $export->disk)->download($export->path, $export->filename ?? basename($export->path));
    }

    public function markFailed(DataExport $export, string $message): void
    {
        $export->forceFill(['status' => DataExport::STATUS_FAILED, 'error' => mb_substr($message, 0, 1000), 'completed_at' => now()])->save();
    }

    public function failStuck(): int
    {
        $limit = now()->subMinutes((int) config('export.stuck_minutes', 60));

        return DataExport::query()
            ->where('status', DataExport::STATUS_PROCESSING)
            ->where('updated_at', '<', $limit)
            ->update(['status' => DataExport::STATUS_FAILED, 'error' => 'Interrumpida: el proceso no terminó a tiempo.', 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function prune(): int
    {
        $count = 0;

        DataExport::query()->where('expires_at', '<', now())->orderBy('id')->chunkById(200, function ($exports) use (&$count): void {
            foreach ($exports as $export) {
                if ($export->path !== null && $export->disk !== null) {
                    $this->files->delete($export->path, $export->disk);
                }
                $export->delete();
                $count++;
            }
        });

        return $count;
    }

    private function exporter(string $key): Exporter
    {
        $class = config("export.exporters.{$key}");

        if (! is_string($class) || ! is_subclass_of($class, Exporter::class)) {
            throw ExportImportException::unknownExporter($key);
        }

        return app($class);
    }

    private function tenantId(): ?int
    {
        return $this->tenant->enabled() ? $this->tenant->id() : null;
    }

    private function notify(DataExport $export, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        try {
            app(NotificationServiceInterface::class)->notify($user, 'export_ready', [
                'export_id' => $export->getKey(),
                'action_url' => $this->temporaryUrl($export),
            ]);
        } catch (Throwable $e) {
            LogService::warning('No se pudo notificar la exportación', ['export_id' => $export->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
