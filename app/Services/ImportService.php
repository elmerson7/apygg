<?php

namespace App\Services;

use App\Contracts\FileServiceInterface;
use App\Contracts\ImportServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Exceptions\ExportImport\ExportImportException;
use App\Imports\Importer;
use App\Jobs\PreviewImportJob;
use App\Jobs\ProcessImportJob;
use App\Models\DataImport;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetWriter;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Importación en dos pasos: subir y validar (vista previa, no guarda nada) y confirmar (procesa por lotes).
 * La confirmación es un cambio de estado atómico (solo una vez) y el importador es idempotente por clave
 * natural, así que repetir un archivo o un job no duplica datos.
 */
class ImportService implements ImportServiceInterface
{
    public function __construct(
        private readonly FileServiceInterface $files,
        private readonly TenantContext $tenant,
    ) {}

    public function upload(User $user, string $importerKey, UploadedFile $file): DataImport
    {
        $importer = $this->importer($importerKey);

        if (! $user->can($importer->permission())) {
            throw new AuthorizationException;
        }

        $tenantId = $this->tenantId();

        if ($importer->requiresTenant() && $tenantId === null) {
            throw ExportImportException::tenantRequired();
        }

        $ext = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());
        $stored = $this->files->upload($file, 'imports', Str::ulid().'.'.$ext, $this->files->diskFor(false), false);

        $import = DataImport::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->getKey(),
            'importer' => $importerKey,
            'status' => DataImport::STATUS_VALIDATING,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'file_hash' => $hash,
        ]);

        PreviewImportJob::dispatch((int) $import->getKey());

        return $import;
    }

    public function preview(DataImport $import): void
    {
        if ($import->status !== DataImport::STATUS_VALIDATING) {
            return;
        }

        try {
            $stats = $this->scan($import, $this->importer($import->importer), null);

            $warning = DataImport::query()
                ->visibleTo((int) $import->user_id, $import->tenant_id)
                ->where('file_hash', $import->file_hash)
                ->where('status', DataImport::STATUS_COMPLETED)
                ->exists() ? 'same_file_already_imported' : null;

            $import->transition(DataImport::STATUS_VALIDATING, DataImport::STATUS_PREVIEWED, [
                'total_rows' => $stats['total'],
                'valid_rows' => $stats['valid'],
                'invalid_rows' => $stats['invalid'],
                'preview' => json_encode(['headers' => $stats['headers'], 'sample' => $stats['sample'], 'errors' => $stats['errors'], 'warning' => $warning]),
                'errors_path' => $stats['errors_path'],
            ]);
        } catch (ExportImportException $e) {
            $this->markFailed($import, $e->getMessage());
        } catch (Throwable $e) {
            LogService::error('Vista previa de importación fallida', ['import_id' => $import->getKey(), 'error' => $e->getMessage()]);
            $this->markFailed($import, 'No se pudo leer el archivo.');
        }
    }

    public function confirm(User $user, int $id): DataImport
    {
        $import = $this->findFor($user, $id);

        if ($import->status === DataImport::STATUS_PREVIEWED && $import->valid_rows === 0) {
            throw ExportImportException::nothingToImport();
        }

        // Atómico: dos confirmaciones simultáneas -> solo una gana, la otra recibe 409.
        if (! $import->transition(DataImport::STATUS_PREVIEWED, DataImport::STATUS_QUEUED, ['confirmed_at' => now()])) {
            throw ExportImportException::notPreviewed($import->status);
        }

        ProcessImportJob::dispatch((int) $import->getKey());

        return $import;
    }

    public function process(DataImport $import): void
    {
        if (! $import->transition(DataImport::STATUS_QUEUED, DataImport::STATUS_PROCESSING)) {
            return; // otro worker lo tiene o ya terminó
        }

        $created = 0;
        $skipped = 0;
        $failures = [];
        $importer = $this->importer($import->importer);

        try {
            $stats = $this->scan($import, $importer, function (array $batch) use ($importer, $import, &$created, &$skipped, &$failures): void {
                $result = $importer->process($batch, $import->tenant_id);
                $created += $result['created'];
                $skipped += $result['skipped'];
                array_push($failures, ...$result['errors']);
            }, $failures);

            $import->transition(DataImport::STATUS_PROCESSING, DataImport::STATUS_COMPLETED, [
                'total_rows' => $stats['total'],
                'valid_rows' => $stats['valid'],
                'invalid_rows' => $stats['invalid'] + count($failures),
                'created_rows' => $created,
                'skipped_rows' => $skipped,
                'errors_path' => $stats['errors_path'],
                'completed_at' => now(),
            ]);
            $this->notifyFinished($import, "{$created} creadas, {$skipped} ya existían, ".($stats['invalid'] + count($failures)).' con error.');
        } catch (Throwable $e) {
            LogService::error('Importación fallida', ['import_id' => $import->getKey(), 'error' => $e->getMessage()]);
            $this->markFailed($import, 'La importación falló: '.$e->getMessage());
        }
    }

    public function paginateFor(User $user, int $perPage): LengthAwarePaginator
    {
        return DataImport::query()->visibleTo((int) $user->getKey(), $this->tenantId())->orderByDesc('id')->paginate($perPage);
    }

    public function findFor(User $user, int $id): DataImport
    {
        return DataImport::query()->visibleTo((int) $user->getKey(), $this->tenantId())->findOrFail($id);
    }

    public function errorsReport(DataImport $import): StreamedResponse
    {
        if ($import->errors_path === null || ! Storage::disk($import->disk)->exists($import->errors_path)) {
            throw ExportImportException::fileMissing();
        }

        return Storage::disk($import->disk)->download($import->errors_path, 'errores-importacion-'.$import->getKey().'.csv');
    }

    public function markFailed(DataImport $import, string $message): void
    {
        $import->forceFill(['status' => DataImport::STATUS_FAILED, 'error' => mb_substr($message, 0, 1000), 'completed_at' => now()])->save();
    }

    public function failStuck(): int
    {
        $limit = now()->subMinutes((int) config('export.stuck_minutes', 60));

        return DataImport::query()
            ->whereIn('status', [DataImport::STATUS_PROCESSING, DataImport::STATUS_VALIDATING, DataImport::STATUS_QUEUED])
            ->where('updated_at', '<', $limit)
            ->update(['status' => DataImport::STATUS_FAILED, 'error' => 'Interrumpida: el proceso no terminó a tiempo. Vuelve a subir el archivo (no duplica datos).', 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function prune(): int
    {
        $count = 0;
        $cutoff = now()->subDays((int) config('export.import_retention_days', 30));

        DataImport::query()->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(200, function ($imports) use (&$count): void {
            foreach ($imports as $import) {
                foreach (array_filter([$import->path, $import->errors_path]) as $path) {
                    $this->files->delete($path, $import->disk);
                }
                $import->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Recorre el archivo en streaming validando cada fila. Con `$onBatch` entrega los lotes de filas válidas.
     * Escribe el informe de errores (CSV) y devuelve estadísticas y muestras.
     *
     * @param  Closure(list<array{row: int, data: array<string, mixed>}>): void|null  $onBatch
     * @param  list<array{row: int, message: string}>  $extraErrors  errores de proceso a añadir al informe
     * @return array{total: int, valid: int, invalid: int, headers: list<string>, sample: list<array<string, mixed>>, errors: list<array<string, mixed>>, errors_path: string|null}
     */
    private function scan(DataImport $import, Importer $importer, ?Closure $onBatch, array &$extraErrors = []): array
    {
        $format = str_ends_with($import->path, '.xlsx') ? 'xlsx' : 'csv';
        $user = User::query()->find($import->user_id);
        $locale = $user?->locale ?: app()->getLocale();
        $max = (int) config('export.import.max_rows', 50000);
        $batchSize = max(1, (int) config('export.import.batch_size', 500));
        $sampleMax = (int) config('export.import.sample_rows', 10);
        $errorMax = (int) config('export.import.error_sample', 50);

        $source = $this->localCopy($import);
        $errTmp = tempnam(sys_get_temp_dir(), 'imperr_');
        $errWriter = new SpreadsheetWriter($errTmp, 'csv');

        $known = array_merge($importer->requiredHeaders(), $importer->optionalHeaders());
        $headers = null;
        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'headers' => [], 'sample' => [], 'errors' => [], 'errors_path' => null];
        $batch = [];
        $processErrors = [];

        try {
            foreach (SpreadsheetReader::rows($source, $format) as $line => $cells) {
                if ($headers === null) {
                    $headers = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $cells);
                    $missing = array_values(array_diff($importer->requiredHeaders(), $headers));

                    if ($missing !== []) {
                        throw ExportImportException::invalidFile('Faltan columnas obligatorias: '.implode(', ', $missing).'.');
                    }

                    $stats['headers'] = array_values(array_intersect($headers, $known));
                    $errWriter->write(array_merge(['row', 'errors'], $stats['headers']));

                    continue;
                }

                if (array_filter($cells, fn ($c) => $c !== null) === []) {
                    continue; // fila vacía
                }

                if (++$stats['total'] > $max) {
                    throw ExportImportException::invalidFile("El archivo supera el máximo de {$max} filas.");
                }

                $data = [];
                foreach ($headers as $i => $name) {
                    if (in_array($name, $known, true)) {
                        $data[$name] = $cells[$i] ?? null;
                    }
                }

                $validator = Validator::make($data, $importer->rules());
                $failed = $this->withLocale($locale, fn () => $validator->fails());

                if ($failed) {
                    $messages = $this->withLocale($locale, fn () => $validator->errors()->toArray());
                    $stats['invalid']++;
                    $errWriter->write(array_merge([$line, $this->flatten($messages)], array_map(fn ($h) => $data[$h] ?? null, $stats['headers'])));

                    if (count($stats['errors']) < $errorMax) {
                        $stats['errors'][] = ['row' => $line, 'errors' => $messages];
                    }

                    continue;
                }

                $stats['valid']++;

                if (count($stats['sample']) < $sampleMax) {
                    $stats['sample'][] = $data;
                }

                if ($onBatch !== null) {
                    $batch[] = ['row' => $line, 'data' => $data];

                    if (count($batch) >= $batchSize) {
                        $onBatch($batch);
                        $batch = [];
                    }
                }
            }

            if ($headers === null) {
                throw ExportImportException::invalidFile('El archivo está vacío.');
            }

            if ($onBatch !== null && $batch !== []) {
                $onBatch($batch);
            }

            // Errores detectados al guardar (p. ej. un dato en conflicto): al final del informe.
            foreach ($extraErrors as $err) {
                $errWriter->write(array_merge([$err['row'], $err['message']], array_fill(0, count($stats['headers']), null)));
            }

            $errWriter->close();

            if ($stats['invalid'] + count($extraErrors) > 0) {
                $stats['errors_path'] = $this->storeReport($errTmp);
            }

            return $stats;
        } finally {
            @unlink($source);
            @unlink($errTmp);
        }
    }

    /**
     * Ejecuta `$callback` con el idioma indicado y lo restaura SIEMPRE (Octane: el locale no puede quedar cambiado).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLocale(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function notifyFinished(DataImport $import, string $message): void
    {
        try {
            $user = User::query()->find($import->user_id);

            if ($user !== null) {
                app(NotificationServiceInterface::class)->notify($user, 'import_finished', ['import_id' => $import->getKey(), 'message' => $message]);
            }
        } catch (Throwable $e) {
            LogService::warning('No se pudo notificar la importación', ['import_id' => $import->getKey(), 'error' => $e->getMessage()]);
        }
    }

    private function storeReport(string $tmp): string
    {
        $name = Str::ulid().'.csv';
        $stored = $this->files->upload(new UploadedFile($tmp, $name, 'text/csv', null, true), 'imports/errors', $name, $this->files->diskFor(false), false);

        return $stored['path'];
    }

    /**
     * @param  array<string, array<string>>  $messages
     */
    private function flatten(array $messages): string
    {
        $out = [];
        foreach ($messages as $field => $list) {
            $out[] = $field.': '.implode(' ', $list);
        }

        return implode(' | ', $out);
    }

    private function localCopy(DataImport $import): string
    {
        $stream = Storage::disk($import->disk)->readStream($import->path);

        if (! is_resource($stream)) {
            throw ExportImportException::fileMissing();
        }

        $tmp = tempnam(sys_get_temp_dir(), 'imp_');
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw ExportImportException::fileMissing();
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $tmp;
    }

    private function importer(string $key): Importer
    {
        $class = config("export.importers.{$key}");

        if (! is_string($class) || ! is_subclass_of($class, Importer::class)) {
            throw ExportImportException::unknownImporter($key);
        }

        return app($class);
    }

    private function tenantId(): ?int
    {
        return $this->tenant->enabled() ? $this->tenant->id() : null;
    }
}
