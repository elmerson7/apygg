<?php

namespace App\Services;

use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\MultipartStorageInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\DirectUploadException;
use App\Models\File;
use App\Models\FileMultipartUpload;
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

    /** Límites de S3 para subidas por partes. */
    private const PART_MIN_BYTES = 5 * 1024 * 1024;

    private const PART_MAX_BYTES = 5 * 1024 * 1024 * 1024;

    private const PARTS_HARD_MAX = 10000;

    private const MIB = 1024 * 1024;

    public function __construct(
        private readonly FileServiceInterface $files,
        private readonly MultipartStorageInterface $storage,
    ) {}

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
            $intent = FileUploadIntent::query()->create($this->intentData($user, $name, $filename, $path, $disk, $mime, $extension, $size, $checksum, $category, $data['description'] ?? null, $isPublic, $expiresAt));

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

    public function createMultipart(User $user, array $data): array
    {
        if (! (bool) config('files.multipart.enabled', true)) {
            throw DirectUploadException::multipartUnsupported('La subida por partes está desactivada.');
        }

        $name = $this->cleanName((string) $data['name']);
        $mime = strtolower(trim((string) $data['mime_type']));
        $size = (int) $data['size'];
        $isPublic = (bool) ($data['is_public'] ?? false);
        $category = (string) ($data['category'] ?? 'default');
        $checksum = isset($data['checksum_sha256']) ? strtolower((string) $data['checksum_sha256']) : null;

        $extension = $this->validateDeclared($name, $mime, $size, max(1, (int) config('files.multipart.max_size_mb', 5120)) * self::MIB);
        $this->files->ensureFileSizeAllowed($size);
        $this->files->assertVisibilityAllowed($isPublic);

        $disk = $this->files->diskFor($isPublic);
        if (! $this->storage->supports($disk)) {
            throw DirectUploadException::multipartUnsupported("El disco '{$disk}' no admite subida por partes (solo S3).");
        }

        [$partSize, $partsCount] = $this->partLayout($size);

        $filename = strtolower((string) Str::ulid()).($extension !== '' ? ".{$extension}" : '');
        $directory = (string) config("files.storage_paths.{$category}", config('files.storage_paths.default', 'uploads'));
        $path = $this->files->tenantStoragePath($directory).'/'.$filename;
        $expiresAt = now()->addMinutes(max(1, (int) config('files.multipart.intent_minutes', 1440)));

        // Solo la reserva va bajo el bloqueo del tenant; la llamada al proveedor se hace después, fuera.
        $intent = $this->files->withStorageQuota($size, fn (): FileUploadIntent => FileUploadIntent::query()->create(
            $this->intentData($user, $name, $filename, $path, $disk, $mime, $extension, $size, $checksum, $category, $data['description'] ?? null, $isPublic, $expiresAt)
        ));

        try {
            $uploadId = $this->storage->initiate($disk, $path, $mime);
            FileMultipartUpload::query()->create([
                'file_upload_intent_id' => $intent->id,
                'upload_id' => $uploadId,
                'part_size' => $partSize,
                'parts_count' => $partsCount,
            ]);
        } catch (\Throwable $e) {
            // Sin subida abierta no hay nada que reservar: se cierra para devolver la cuota ya.
            $intent->update(['status' => FileUploadIntent::STATUS_ABORTED]);
            LogService::error('No se pudo abrir la subida por partes', ['disk' => $disk, 'error' => $e->getMessage()], 'files');

            throw DirectUploadException::storageError();
        }

        return ['intent' => $intent, 'part_size' => $partSize, 'parts_count' => $partsCount];
    }

    public function presignParts(FileUploadIntent $intent, array $partNumbers): array
    {
        $multipart = $this->openMultipart($intent);
        $numbers = array_values(array_unique($partNumbers));

        $max = max(1, (int) config('files.multipart.max_urls_per_request', 100));
        if ($numbers === [] || count($numbers) > $max) {
            throw DirectUploadException::partMismatch("pide entre 1 y {$max} partes por petición");
        }

        foreach ($numbers as $n) {
            if ($n < 1 || $n > $multipart->parts_count) {
                throw DirectUploadException::partMismatch("la parte {$n} no existe (1..{$multipart->parts_count})");
            }
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('files.multipart.url_minutes', 60)));
        if ($expiresAt->greaterThan($intent->expires_at)) {
            $expiresAt = $intent->expires_at;
        }

        $parts = [];
        foreach ($numbers as $n) {
            $signed = $this->storage->presignPart($intent->disk, $intent->path, $multipart->upload_id, $n, $expiresAt);
            $parts[] = ['part_number' => $n, 'url' => $signed['url'], 'headers' => $signed['headers']];
        }

        return [
            'part_size' => $multipart->part_size,
            'parts_count' => $multipart->parts_count,
            'expires_at' => $expiresAt->toIso8601String(),
            'parts' => $parts,
        ];
    }

    public function listParts(FileUploadIntent $intent): array
    {
        $multipart = $this->openMultipart($intent);

        $uploaded = $this->storage->listParts($intent->disk, $intent->path, $multipart->upload_id) ?? [];
        $parts = [];
        foreach ($uploaded as $number => $part) {
            $parts[] = ['part_number' => $number, 'size' => $part['size'], 'etag' => $part['etag']];
        }

        return ['part_size' => $multipart->part_size, 'parts_count' => $multipart->parts_count, 'parts' => $parts];
    }

    public function abort(FileUploadIntent $intent): void
    {
        DB::transaction(function () use ($intent): void {
            $row = FileUploadIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($row->status === FileUploadIntent::STATUS_COMPLETED) {
                throw DirectUploadException::completed();
            }

            if ($row->status === FileUploadIntent::STATUS_ABORTED) {
                return;
            }

            // Si el proveedor falla no se cierra: así la reserva no se libera con partes huérfanas y se puede reintentar.
            $this->discardObject($row, strict: true);
            $row->update(['status' => FileUploadIntent::STATUS_ABORTED]);
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
                $this->assembleParts($row);
                $this->verifyObject($row);
            } catch (DirectUploadException $e) {
                if (in_array($e->errorCode(), ['upload_object_missing', 'upload_parts_missing', 'upload_storage_error'], true)) {
                    throw $e;
                }

                // Contenido que no es lo declarado: se descarta (el estado se confirma antes de avisar).
                $this->discardObject($row, strict: true);
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
                        // Si el proveedor no deja abortar una subida por partes, la fila se queda para el próximo barrido.
                        try {
                            $this->discardObject($intent, strict: true);
                        } catch (DirectUploadException) {
                            continue;
                        }

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
    private function validateDeclared(string $name, string $mime, int $size, ?int $maxBytes = null): string
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

        $max = $maxBytes ?? (int) config('files.max_sizes.'.$this->files->detectType($mime), config('files.max_sizes.default', 10 * 1024 * 1024));
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

    /**
     * Borra el objeto de una intención y, si era por partes, aborta la subida en el proveedor.
     * `strict`: un fallo al abortar las partes se propaga (`upload_storage_error`) en vez de callarse,
     * porque las partes huérfanas cuestan dinero y no deben quedar sin dueño.
     *
     * @throws DirectUploadException
     */
    private function discardObject(FileUploadIntent $intent, bool $strict = false): void
    {
        $multipart = $intent->multipart;

        if ($multipart !== null) {
            try {
                $this->storage->abort($intent->disk, $intent->path, $multipart->upload_id);
            } catch (\Throwable $e) {
                LogService::warning('No se pudo abortar la subida por partes', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');

                if ($strict) {
                    throw DirectUploadException::storageError();
                }
            }
        }

        try {
            Storage::disk($intent->disk)->delete($intent->path);
        } catch (\Throwable $e) {
            LogService::warning('No se pudo borrar el objeto de una subida descartada', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');
        }
    }

    /**
     * Intención abierta (pendiente y vigente) de tipo multipart.
     *
     * @throws DirectUploadException
     */
    private function openMultipart(FileUploadIntent $intent): FileMultipartUpload
    {
        $multipart = $intent->multipart;

        if ($multipart === null) {
            throw DirectUploadException::multipartUnsupported('La intención no es de subida por partes.');
        }

        if ($intent->status === FileUploadIntent::STATUS_ABORTED) {
            throw DirectUploadException::aborted();
        }

        if ($intent->status === FileUploadIntent::STATUS_COMPLETED) {
            throw DirectUploadException::completed();
        }

        if ($intent->isExpired()) {
            throw DirectUploadException::expired();
        }

        return $multipart;
    }

    /**
     * Une las partes en el objeto final (solo intenciones por partes). Llamar con la fila bloqueada.
     * Comprueba que están TODAS y que cada una mide lo que toca; si el proveedor ya no conoce la subida
     * (una unión anterior se hizo pero no llegó a confirmarse en BD) no hace nada y `verifyObject` decide.
     *
     * @throws DirectUploadException
     */
    private function assembleParts(FileUploadIntent $intent): void
    {
        $multipart = $intent->multipart;

        if ($multipart === null) {
            return;
        }

        try {
            $uploaded = $this->storage->listParts($intent->disk, $intent->path, $multipart->upload_id);
        } catch (\Throwable $e) {
            LogService::error('No se pudieron listar las partes', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');

            throw DirectUploadException::storageError();
        }

        if ($uploaded === null) {
            return;
        }

        $missing = [];
        $etags = [];
        for ($n = 1; $n <= $multipart->parts_count; $n++) {
            if (! isset($uploaded[$n])) {
                $missing[] = $n;

                continue;
            }

            $expected = $n < $multipart->parts_count ? $multipart->part_size : $intent->size - ($multipart->parts_count - 1) * $multipart->part_size;
            if ($uploaded[$n]['size'] !== $expected) {
                throw DirectUploadException::partMismatch("la parte {$n} mide {$uploaded[$n]['size']} bytes y debía medir {$expected}");
            }

            $etags[$n] = $uploaded[$n]['etag'];
        }

        if ($missing !== []) {
            throw DirectUploadException::partsMissing($missing, $multipart->parts_count);
        }

        if (count($uploaded) > $multipart->parts_count) {
            throw DirectUploadException::partMismatch('hay partes de más');
        }

        try {
            $this->storage->complete($intent->disk, $intent->path, $multipart->upload_id, $etags);
        } catch (\Throwable $e) {
            LogService::error('No se pudo unir la subida por partes', ['path' => $intent->path, 'error' => $e->getMessage()], 'files');

            throw DirectUploadException::storageError();
        }
    }

    /**
     * Tamaño de parte y número de partes: el base de config, agrandado (a múltiplo de 1 MiB) si el
     * archivo no cabría en `max_parts`.
     *
     * @return array{0: int, 1: int}
     *
     * @throws BusinessLogicException
     */
    private function partLayout(int $size): array
    {
        $maxParts = min(self::PARTS_HARD_MAX, max(1, (int) config('files.multipart.max_parts', self::PARTS_HARD_MAX)));
        $base = max(self::PART_MIN_BYTES, (int) config('files.multipart.part_size_mb', 16) * self::MIB);
        $partSize = (int) (ceil(max($base, (int) ceil($size / $maxParts)) / self::MIB) * self::MIB);

        if ($partSize > self::PART_MAX_BYTES) {
            throw BusinessLogicException::ruleViolation('El archivo excede el tamaño máximo permitido.');
        }

        return [$partSize, (int) ceil($size / $partSize)];
    }

    /**
     * @return array<string, mixed>
     */
    private function intentData(User $user, string $name, string $filename, string $path, string $disk, string $mime, string $extension, int $size, ?string $checksum, string $category, ?string $description, bool $isPublic, \DateTimeInterface $expiresAt): array
    {
        return [
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
            'description' => $description,
            'is_public' => $isPublic,
            'status' => FileUploadIntent::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ];
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', basename(str_replace('\\', '/', $name))) ?: 'archivo';

        return mb_substr($name, 0, 255);
    }
}
