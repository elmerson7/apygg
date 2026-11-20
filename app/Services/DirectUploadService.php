<?php

namespace App\Services;

use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\DirectUploadException;
use App\Models\File;
use App\Models\FileUploadIntent;
use App\Models\User;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * DirectUploadService
 *
 * Subida directa presignada con reserva de cuota. La intención `pending` y vigente cuenta como uso
 * del tenant (FileService::usedStorageBytes), así dos intenciones simultáneas no pueden pasarse de la
 * cuota; al completar, los mismos bytes pasan de reserva a `File` en una transacción (el total no cambia).
 * Sin estado entre peticiones (Octane).
 */
class DirectUploadService implements DirectUploadServiceInterface
{
    /** Bytes leídos para detectar el tipo real del objeto. */
    private const SNIFF_BYTES = 8192;

    /** Equivalencias de tipo que finfo no distingue por contenido (declarado => detectados válidos). */
    private const MIME_FAMILIES = [
        'text/csv' => ['text/plain', 'text/csv', 'application/csv'],
        'text/plain' => ['text/plain', 'text/csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['application/zip', 'application/octet-stream'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['application/zip', 'application/octet-stream'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['application/zip', 'application/octet-stream'],
        'application/msword' => ['application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'application/vnd.ms-excel' => ['application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'application/vnd.ms-powerpoint' => ['application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    ];

    public function __construct(private readonly FileServiceInterface $files) {}

    public function create(User $user, array $data): array
    {
        $name = $this->cleanName((string) $data['name']);
        $mime = strtolower(trim((string) $data['mime_type']));
        $size = (int) $data['size'];
        $isPublic = (bool) ($data['is_public'] ?? false);
        $category = (string) ($data['category'] ?? 'default');
        $checksum = isset($data['checksum_sha256']) ? strtolower((string) $data['checksum_sha256']) : null;

        $extension = $this->validateDeclared($name, $mime, $size);
        $this->files->ensureFileSizeAllowed($size);
        $this->files->assertVisibilityAllowed($isPublic);

        $disk = $this->files->diskFor($isPublic);
        $adapter = Storage::disk($disk);

        if (! $adapter->providesTemporaryUploadUrls()) {
            throw DirectUploadException::unsupported($disk);
        }

        $filename = strtolower((string) Str::ulid()).($extension !== '' ? ".{$extension}" : '');
        $directory = (string) config("files.storage_paths.{$category}", config('files.storage_paths.default', 'uploads'));
        $path = $this->files->tenantStoragePath($directory).'/'.$filename;
        $expiresAt = now()->addMinutes(max(1, (int) config('files.direct_upload.intent_minutes', 30)));

        // Reserva de cuota bajo el bloqueo del tenant; la URL se firma dentro para no dejar una reserva sin URL.
        return $this->files->withStorageQuota($size, function () use ($user, $adapter, $name, $filename, $path, $disk, $mime, $extension, $size, $checksum, $category, $data, $isPublic, $expiresAt) {
            $intent = FileUploadIntent::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'name' => $name,
                'filename' => $filename,
                'path' => $path,
                'disk' => $disk,
                'mime_type' => $mime,
                'extension' => $extension,
                'size' => $size,
                'checksum_sha256' => $checksum,
                'category' => $category,
                'description' => $data['description'] ?? null,
                'is_public' => $isPublic,
                'status' => FileUploadIntent::STATUS_PENDING,
                'expires_at' => $expiresAt,
            ]);

            /** @var array{url: string, headers?: array<string, mixed>} $signed */
            $signed = $adapter->temporaryUploadUrl($path, $expiresAt, ['ContentType' => $mime]);

            return [
                'intent' => $intent,
                'upload' => [
                    'method' => 'PUT',
                    'url' => $signed['url'],
                    'headers' => $signed['headers'] ?? [],
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ];
        });
    }

    public function find(string $uuid): FileUploadIntent
    {
        return FileUploadIntent::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function complete(FileUploadIntent $intent): File
    {
        $failure = null;

        $file = DB::transaction(function () use ($intent, &$failure): ?File {
            // La fila de la intención se bloquea: dos "complete" simultáneos no crean dos archivos.
            $row = FileUploadIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($row->status === FileUploadIntent::STATUS_COMPLETED) {
                return File::query()->findOrFail($row->file_id);
            }

            if ($row->status === FileUploadIntent::STATUS_ABORTED) {
                throw DirectUploadException::aborted();
            }

            if ($row->isExpired()) {
                throw DirectUploadException::expired();
            }

            try {
                $this->verifyObject($row);
            } catch (DirectUploadException $e) {
                if ($e->errorCode() === 'upload_object_missing') {
                    throw $e;
                }

                // Contenido que no es lo declarado: se descarta (el estado se confirma antes de avisar).
                $this->discardObject($row);
                $row->update(['status' => FileUploadIntent::STATUS_ABORTED]);
                $failure = $e;

                return null;
            }

            $file = $this->createFile($row);
            $row->update([
                'status' => FileUploadIntent::STATUS_COMPLETED,
                'completed_at' => now(),
                'file_id' => $file->id,
            ]);

            return $file;
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $file ?? throw DirectUploadException::aborted();
    }

    public function purgeExpired(): int
    {
        return TenantQuery::withoutTenant(function (): int {
            $deleted = 0;

            FileUploadIntent::query()
                ->where('status', FileUploadIntent::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->chunkById(200, function ($intents) use (&$deleted): void {
                    foreach ($intents as $intent) {
                        $this->discardObject($intent);
                        $deleted += FileUploadIntent::query()->whereKey($intent->id)->forceDelete();
                    }
                });

            $retention = max(1, (int) config('files.direct_upload.retention_days', 7));

            return $deleted + FileUploadIntent::query()
                ->whereIn('status', [FileUploadIntent::STATUS_COMPLETED, FileUploadIntent::STATUS_ABORTED])
                ->where('updated_at', '<', now()->subDays($retention))
                ->forceDelete();
        }, 'files: purgar intenciones de subida caducadas');
    }

    /**
     * Valida lo declarado con las mismas reglas que la subida normal y devuelve la extensión final.
     *
     * @throws BusinessLogicException
     */
    private function validateDeclared(string $name, string $mime, int $size): string
    {
        $client = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($mime, (array) config('files.blocked_mimes', []), true)
            || in_array($client, (array) config('files.blocked_extensions', []), true)) {
            throw BusinessLogicException::ruleViolation('Tipo de archivo no permitido.');
        }

        $allowedMimes = array_unique(array_merge(...array_values((array) config('files.allowed_mimes', []))));
        if (! in_array($mime, $allowedMimes, true)) {
            throw BusinessLogicException::ruleViolation('Tipo de archivo no permitido.');
        }

        $allowedExtensions = array_unique(array_merge(...array_values((array) config('files.allowed_extensions', []))));
        if (! in_array($client, $allowedExtensions, true)) {
            throw BusinessLogicException::ruleViolation('Extensión de archivo no permitida.');
        }

        $max = (int) config('files.max_sizes.'.$this->files->detectType($mime), config('files.max_sizes.default', 10 * 1024 * 1024));
        if ($size < 1 || $size > $max) {
            throw BusinessLogicException::ruleViolation('El archivo excede el tamaño máximo permitido.');
        }

        $candidates = MimeTypes::getDefault()->getExtensions($mime);

        if ($mime === 'text/plain' && in_array($client, ['txt', 'csv'], true)) {
            return $client;
        }

        $extension = in_array($client, $candidates, true) ? $client : ($candidates[0] ?? $client);

        return preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
    }

    /**
     * Comprueba el objeto REAL en el disco: existe, tamaño, tipo por contenido y checksum.
     *
     * @throws DirectUploadException
     */
    private function verifyObject(FileUploadIntent $intent): void
    {
        $disk = Storage::disk($intent->disk);

        if (! $disk->exists($intent->path)) {
            throw DirectUploadException::objectMissing();
        }

        $actual = (int) $disk->size($intent->path);
        if ($actual !== $intent->size) {
            throw DirectUploadException::sizeMismatch($intent->size, $actual);
        }

        $stream = $disk->readStream($intent->path);
        if (! is_resource($stream)) {
            throw DirectUploadException::objectMissing();
        }

        try {
            $head = (string) stream_get_contents($stream, self::SNIFF_BYTES);
            $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
            $this->assertMimeMatches($intent->mime_type, $detected);

            if ($intent->checksum_sha256 !== null) {
                $hash = hash_init('sha256');
                hash_update($hash, $head);
                hash_update_stream($hash, $stream);

                if (! hash_equals($intent->checksum_sha256, hash_final($hash))) {
                    throw DirectUploadException::checksumMismatch();
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @throws DirectUploadException
     */
    private function assertMimeMatches(string $declared, string $detected): void
    {
        if (in_array($detected, (array) config('files.blocked_mimes', []), true)) {
            throw DirectUploadException::mimeMismatch($declared, $detected);
        }

        $valid = array_merge([$declared], self::MIME_FAMILIES[$declared] ?? []);

        if (! in_array($detected, $valid, true)) {
            throw DirectUploadException::mimeMismatch($declared, $detected);
        }
    }

    private function createFile(FileUploadIntent $intent): File
    {
        $disk = Storage::disk($intent->disk);

        try {
            $disk->setVisibility($intent->path, $intent->is_public ? 'public' : 'private');
        } catch (\Throwable $e) {
            LogService::warning('No se pudo fijar la visibilidad del objeto subido', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');
        }

        $retentionDays = config("files.retention_policies.{$intent->category}");

        return File::query()->create([
            'user_id' => $intent->user_id,
            'name' => $intent->name,
            'filename' => $intent->filename,
            'path' => $intent->path,
            'url' => $intent->is_public ? $this->files->getUrl($intent->path, $intent->disk) : null,
            'disk' => $intent->disk,
            'mime_type' => $intent->mime_type,
            'extension' => $intent->extension,
            'size' => $intent->size,
            'type' => $this->files->detectType($intent->mime_type),
            'category' => $intent->category,
            'description' => $intent->description,
            'is_public' => $intent->is_public,
            'expires_at' => $retentionDays ? now()->addDays((int) $retentionDays) : null,
        ]);
    }

    private function discardObject(FileUploadIntent $intent): void
    {
        try {
            Storage::disk($intent->disk)->delete($intent->path);
        } catch (\Throwable $e) {
            LogService::warning('No se pudo borrar el objeto de una subida descartada', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');
        }
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', basename(str_replace('\\', '/', $name))) ?: 'archivo';

        return mb_substr($name, 0, 255);
    }
}
