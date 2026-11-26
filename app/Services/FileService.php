<?php

namespace App\Services;

use App\Contracts\FileServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\SettingsRegistryInterface;
use App\Enums\FileScanStatus;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\FileTooLargeForPlanException;
use App\Exceptions\PlanLimitReachedException;
use App\Exceptions\PublicSharingDisabledException;
use App\Exceptions\StorageQuotaExceededException;
use App\Models\File;
use App\Models\FileUploadIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Symfony\Component\Mime\MimeTypes;

/**
 * FileService
 *
 * Servicio centralizado para manejo de archivos: upload, delete, getUrl, exists.
 */
class FileService implements FileServiceInterface
{
    protected const DEFAULT_DISK = 'public';

    private const BYTES_PER_MB = 1048576;

    public function __construct(
        private readonly SettingsRegistryInterface $settings,
        private readonly PlanLimitsInterface $limits,
    ) {}

    /**
     * Subir archivo
     *
     * El nombre en disco se GENERA (ULID + extensión deducida del contenido); el nombre
     * original del cliente nunca se usa como ruta (evita path traversal y colisiones).
     * Los archivos privados se guardan con visibilidad `private` y sin URL pública.
     *
     * @return array<string, mixed> ['path', 'url', 'filename', 'size', 'mime_type', 'extension', 'disk']
     *
     * @throws BusinessLogicException si el contenido, la extensión o el tamaño no son válidos
     */
    public function upload(
        UploadedFile $file,
        string $path = 'uploads',
        ?string $filename = null,
        ?string $disk = null,
        bool $isPublic = true
    ): array {
        $disk = $disk ?? self::DEFAULT_DISK;
        $this->validateFile($file);
        $extension = $this->resolveExtension($file);
        $filename = $filename !== null ? basename($filename) : $this->generateFilename($file, $extension);
        $mimeType = $file->getMimeType();
        $this->reencodeImage($file, (string) $mimeType);
        clearstatcache(true, $file->getRealPath());
        $size = (int) filesize($file->getRealPath());

        $storedPath = Storage::disk($disk)->putFileAs(
            $this->tenantStoragePath($path),
            $file,
            $filename,
            ['visibility' => $isPublic ? 'public' : 'private']
        );

        if ($storedPath === false) {
            throw new \RuntimeException('No se pudo guardar el archivo en el almacenamiento.');
        }

        LogService::info('Archivo subido', [
            'path' => $storedPath,
            'disk' => $disk,
            'size' => $size,
            'mime_type' => $mimeType,
            'is_public' => $isPublic,
        ], 'files');

        return [
            'path' => $storedPath,
            // Un archivo privado NUNCA expone URL pública.
            'url' => $isPublic ? $this->getUrl($storedPath, $disk) : null,
            'filename' => $filename,
            'size' => $size,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'disk' => $disk,
        ];
    }

    /**
     * Ruta de almacenamiento: `[<prefijo de entorno>/][tenants/<id>/]<ruta>`. El prefijo
     * (`files.prefix`, por defecto `APP_ENV`) separa entornos que comparten bucket; con multiempresa
     * activo la clave incluye además el tenant, así un tenant nunca comparte carpeta con otro y se
     * puede purgar, medir o respaldar por empresa. Solo afecta a archivos NUEVOS: los ya guardados
     * conservan su `path` en BD.
     */
    public function tenantStoragePath(string $path): string
    {
        $path = trim($path, '/');
        $context = app(TenantContext::class);

        if ($context->enabled()) {
            $path = 'tenants/'.$context->requireId(File::class, 'store').'/'.$path;
        }

        $prefix = $this->storagePrefix();

        return $prefix !== '' ? $prefix.'/'.$path : $path;
    }

    /**
     * Prefijo de entorno saneado (un solo segmento seguro); vacío = sin prefijo.
     */
    public function storagePrefix(): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '', (string) config('files.prefix', '')), '.');
    }

    public function ensureFileSizeAllowed(int $bytes): void
    {
        $limitMb = $this->limits->limit('max_file_mb');

        if ($limitMb !== null && $bytes > $limitMb * self::BYTES_PER_MB) {
            throw new FileTooLargeForPlanException($limitMb * self::BYTES_PER_MB, $bytes);
        }
    }

    /**
     * Bytes que el tenant en contexto ya usa: archivos vivos (sin los infectados) + reservas de subidas directas vigentes.
     * Solo es fiable dentro de `withStorageQuota` (bajo el bloqueo del tenant).
     */
    public function usedStorageBytes(): int
    {
        // Un archivo infectado ya no ocupa almacenamiento (el antivirus borró su contenido).
        return (int) File::query()->where('scan_status', '!=', FileScanStatus::Infected->value)->sum('size')
            + (int) FileUploadIntent::query()->reserving()->sum('size');
    }

    /**
     * Ejecuta `$create` solo si `$bytes` caben en `max_storage_mb` del plan. Cuenta, comprueba y crea EN
     * UNA TRANSACCIÓN con la fila del tenant bloqueada (`PlanLimits::guarded`): dos subidas simultáneas
     * se serializan y no pueden pasarse de la cuota. Sin límites activos ejecuta `$create` tal cual.
     * Ojo: el bloqueo dura lo que dure `$create`; en subidas normales incluye la escritura a disco.
     *
     * @template T
     *
     * @param  \Closure(): T  $create
     * @return T
     *
     * @throws StorageQuotaExceededException
     */
    public function withStorageQuota(int $bytes, \Closure $create): mixed
    {
        try {
            return $this->limits->guarded(
                'max_storage_mb',
                fn (): int => $this->usedStorageBytes(),
                $create,
                $bytes,
                self::BYTES_PER_MB,
            );
        } catch (PlanLimitReachedException $e) {
            throw new StorageQuotaExceededException($e->limit, $e->current, $bytes);
        }
    }

    /**
     * @return array{used_bytes: int, limit_bytes: int|null, remaining_bytes: int|null, max_file_bytes: int|null}
     */
    public function storageUsage(): array
    {
        $storageMb = $this->limits->limit('max_storage_mb');
        $fileMb = $this->limits->limit('max_file_mb');
        $used = $this->usedStorageBytes();
        $limit = $storageMb === null ? null : $storageMb * self::BYTES_PER_MB;

        return [
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'remaining_bytes' => $limit === null ? null : max(0, $limit - $used),
            'max_file_bytes' => $fileMb === null ? null : $fileMb * self::BYTES_PER_MB,
        ];
    }

    public function assertVisibilityAllowed(bool $isPublic): void
    {
        if ($isPublic && ! (bool) $this->settings->get('security.allow_public_sharing')) {
            throw new PublicSharingDisabledException;
        }
    }

    /**
     * Disco configurado para archivos públicos o privados.
     */
    public function diskFor(bool $isPublic): string
    {
        return $isPublic
            ? (string) config('files.disks.public', 'public')
            : (string) config('files.disks.private', 'local');
    }

    /**
     * Mover un archivo entre discos (cambio de visibilidad) conservando su ruta.
     * Copia, y solo si la copia se completó borra el original.
     *
     * @throws \RuntimeException
     */
    public function transfer(string $path, string $fromDisk, string $toDisk, bool $toPublic): void
    {
        if ($fromDisk === $toDisk) {
            Storage::disk($toDisk)->setVisibility($path, $toPublic ? 'public' : 'private');

            return;
        }

        $stream = Storage::disk($fromDisk)->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('No se pudo leer el archivo original.');
        }

        try {
            $ok = Storage::disk($toDisk)->writeStream($path, $stream, ['visibility' => $toPublic ? 'public' : 'private']);
        } finally {
            fclose($stream);
        }

        if (! $ok) {
            throw new \RuntimeException('No se pudo copiar el archivo al disco de destino.');
        }

        Storage::disk($fromDisk)->delete($path);
    }

    /**
     * Actualiza el registro y, si cambia la visibilidad, mueve el archivo al disco público o
     * privado. Si algo falla, el archivo vuelve a su disco original (consistencia BD/disco).
     *
     * @throws \Throwable
     */
    public function updateWithVisibility(File $file, array $data, ?bool $makePublic): void
    {
        $from = $file->disk;
        $moved = false;

        try {
            DB::transaction(function () use ($file, $data, $makePublic, $from, &$moved) {
                if ($makePublic !== null && $makePublic !== $file->is_public) {
                    $to = $this->diskFor($makePublic);
                    $this->transfer($file->path, $from, $to, $makePublic);
                    $moved = $to !== $from;

                    $data['disk'] = $to;
                    $data['url'] = $makePublic ? $this->getUrl($file->path, $to) : null;
                }

                $file->update($data);
            });
        } catch (\Throwable $e) {
            if ($moved) {
                $this->transfer($file->path, $this->diskFor((bool) $makePublic), $from, ! $makePublic);
            }

            throw $e;
        }
    }

    /**
     * URL temporal firmada para descargar un archivo privado.
     *
     * S3/MinIO: URL prefirmada del proveedor. Disco local: ruta firmada de Laravel
     * (`files.signed`), que expira y no necesita sesión.
     */
    public function temporaryDownloadUrl(File $file, ?int $minutes = null): ?string
    {
        $minutes = $minutes ?? (int) config('files.temporary_url_minutes', 15);
        $expiresAt = now()->addMinutes($minutes);

        if (config("filesystems.disks.{$file->disk}.driver") === 's3') {
            try {
                return Storage::disk($file->disk)->temporaryUrl($file->path, $expiresAt, [
                    'ResponseContentDisposition' => 'attachment; filename="'.$this->safeDownloadName($file).'"',
                ]);
            } catch (\Throwable $e) {
                LogService::error('Error al generar URL temporal', ['file_id' => $file->id, 'error' => $e->getMessage()], 'files');

                return null;
            }
        }

        // La URL firmada lleva el tenant (va dentro de la firma): la descarga no tiene sesión ni
        // cabecera X-Tenant y fija el contexto desde aquí.
        $params = ['id' => $file->id];

        if (app(TenantContext::class)->enabled()) {
            $params['tenant'] = (int) $file->getAttribute('tenant_id');
        }

        return URL::temporarySignedRoute('files.signed', $expiresAt, $params);
    }

    /**
     * Nombre de descarga seguro (sin comillas ni saltos de línea ni rutas).
     */
    public function safeDownloadName(File $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', basename((string) $file->name)) ?: 'archivo';

        return mb_substr($name, 0, 200);
    }

    /**
     * Tipo de archivo según su MIME (image, video, audio, archive, document, other).
     */
    public function detectType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            in_array($mimeType, config('files.allowed_mimes.archive', []), true) => 'archive',
            str_starts_with($mimeType, 'application/'), str_starts_with($mimeType, 'text/') => 'document',
            default => 'other',
        };
    }

    /**
     * Subir imagen con procesamiento opcional (resize/formato)
     *
     * @param  array<string, mixed>  $options  width, height, quality, format
     */
    public function uploadImage(
        UploadedFile $file,
        string $path = 'images',
        array $options = []
    ): array {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw BusinessLogicException::ruleViolation('El archivo debe ser una imagen.');
        }

        $width = $options['width'] ?? null;
        $height = $options['height'] ?? null;
        $quality = $options['quality'] ?? 90;
        $format = $options['format'] ?? null;

        if ($width || $height) {
            if (! class_exists(Image::class)) {
                throw new \RuntimeException('Intervention Image package is required for image processing.');
            }

            $image = Image::make($file);

            if ($width && $height) {
                $image->fit($width, $height);
            } elseif ($width) {
                $image->resize($width, null, fn ($c) => $c->aspectRatio());
            } elseif ($height) {
                $image->resize(null, $height, fn ($c) => $c->aspectRatio());
            }

            if ($format) {
                $image->encode($format, $quality);
            }

            $extension = $format ?? $file->getClientOriginalExtension();
            $filename = $this->generateFilename($file, $extension);
            $fullPath = rtrim($this->tenantStoragePath($path), '/').'/'.$filename;
            $disk = self::DEFAULT_DISK;

            Storage::disk($disk)->put($fullPath, $image->stream());

            $result = [
                'path' => $fullPath,
                'url' => Storage::disk($disk)->url($fullPath),
                'filename' => $filename,
                'size' => strlen($image->stream()->getContents()),
                'mime_type' => $image->mime(),
                'width' => $image->width(),
                'height' => $image->height(),
                'disk' => $disk,
            ];

            LogService::info('Imagen subida con procesamiento', [
                'path' => $fullPath,
                'disk' => $disk,
                'width' => $image->width(),
                'height' => $image->height(),
                'mime_type' => $image->mime(),
            ], 'files');

            return $result;
        }

        return $this->upload($file, $path);
    }

    /**
     * Eliminar archivo
     */
    public function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        try {
            $deleted = Storage::disk($disk)->exists($path)
                ? Storage::disk($disk)->delete($path)
                : false;

            if ($deleted) {
                LogService::info('Archivo eliminado', ['path' => $path, 'disk' => $disk], 'files');
            }

            return $deleted;
        } catch (\Exception $e) {
            LogService::error('Error al eliminar archivo', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ], 'files');

            return false;
        }
    }

    /**
     * Obtener URL pública del archivo.
     * Para S3/Minio construye la URL explícitamente usando buildS3Url().
     */
    public function getUrl(string $path, ?string $disk = null): ?string
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        // El disco privado no tiene URL pública, solo URLs temporales.
        if ($disk === config('files.disks.private') && $disk !== config('files.disks.public')) {
            return null;
        }

        try {
            if ($disk === 's3') {
                return $this->buildS3Url($path);
            }

            return Storage::disk($disk)->exists($path)
                ? Storage::disk($disk)->url($path)
                : null;
        } catch (\Exception $e) {
            LogService::error('Error al obtener URL de archivo', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ], 'files');

            return null;
        }
    }

    /**
     * Obtener URL temporal con expiración (para archivos privados S3/Minio).
     *
     * @param  int  $minutes  Minutos de expiración (default 720 = 12h)
     */
    public function getTemporaryUrl(string $path, ?string $disk = null, int $minutes = 720): ?string
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        try {
            if (! Storage::disk($disk)->exists($path)) {
                LogService::warning('Archivo no encontrado para URL temporal', [
                    'path' => $path,
                    'disk' => $disk,
                ], 'files');

                return null;
            }

            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Exception $e) {
            LogService::error('Error al generar URL temporal', [
                'path' => $path,
                'disk' => $disk,
                'minutes' => $minutes,
                'error' => $e->getMessage(),
            ], 'files');

            return null;
        }
    }

    /**
     * Construir URL pública para disco S3/Minio (path-style).
     * Usa AWS_URL (pública) en lugar del endpoint interno.
     * Formato: {url}/{bucket}/{path}
     */
    protected function buildS3Url(string $path): string
    {
        $path = ltrim($path, '/');
        $baseUrl = rtrim(config('filesystems.disks.s3.url', ''), '/');
        $endpoint = rtrim(config('filesystems.disks.s3.endpoint', ''), '/');
        $bucket = config('filesystems.disks.s3.bucket', '');
        $usePathStyle = config('filesystems.disks.s3.use_path_style_endpoint', true);

        $root = $baseUrl !== '' ? $baseUrl : $endpoint;
        if ($root === '') {
            LogService::warning('S3 URL incompleta: falta AWS_URL o AWS_ENDPOINT en .env', [], 'files');

            return $path !== '' ? '/'.$path : '/';
        }

        if ($usePathStyle && $bucket !== '') {
            return $root.'/'.$bucket.'/'.$path;
        }

        return $root.'/'.$path;
    }

    /**
     * Verificar si archivo existe
     */
    public function exists(string $path, ?string $disk = null): bool
    {
        return Storage::disk($disk ?? self::DEFAULT_DISK)->exists($path);
    }

    /**
     * Obtener tamaño del archivo en bytes
     */
    public function getSize(string $path, ?string $disk = null): ?int
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        try {
            return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->size($path) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener información del archivo
     */
    public function getInfo(string $path, ?string $disk = null): ?array
    {
        $disk = $disk ?? self::DEFAULT_DISK;
        if (! $this->exists($path, $disk)) {
            return null;
        }

        return [
            'path' => $path,
            'url' => $this->getUrl($path, $disk),
            'size' => $this->getSize($path, $disk),
            'mime_type' => Storage::disk($disk)->mimeType($path),
            'last_modified' => Storage::disk($disk)->lastModified($path),
        ];
    }

    /**
     * Copiar archivo
     */
    public function copy(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        try {
            return Storage::disk($disk)->copy($fromPath, $toPath);
        } catch (\Exception $e) {
            LogService::error('Error al copiar archivo', [
                'from' => $fromPath,
                'to' => $toPath,
                'error' => $e->getMessage(),
            ], 'files');

            return false;
        }
    }

    /**
     * Mover archivo
     */
    public function move(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::DEFAULT_DISK;

        try {
            return Storage::disk($disk)->move($fromPath, $toPath);
        } catch (\Exception $e) {
            LogService::error('Error al mover archivo', [
                'from' => $fromPath,
                'to' => $toPath,
                'error' => $e->getMessage(),
            ], 'files');

            return false;
        }
    }

    /**
     * Validar archivo antes de subir: el tipo se decide por el CONTENIDO (finfo), no por
     * el nombre ni por el Content-Type que declara el cliente.
     *
     * @throws BusinessLogicException
     */
    protected function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw BusinessLogicException::ruleViolation('El archivo no se subió correctamente.');
        }

        $mime = (string) $file->getMimeType();
        $clientExtension = strtolower($file->getClientOriginalExtension());

        if (in_array($mime, config('files.blocked_mimes', []), true)
            || in_array($clientExtension, config('files.blocked_extensions', []), true)) {
            throw BusinessLogicException::ruleViolation('Tipo de archivo no permitido.');
        }

        $maxSize = config('files.max_sizes.'.$this->detectType($mime), config('files.max_sizes.default', 10 * 1024 * 1024));
        if ($file->getSize() > $maxSize) {
            throw BusinessLogicException::ruleViolation('El archivo excede el tamaño máximo permitido.');
        }

        $allAllowedMimes = array_unique(array_merge(...array_values(config('files.allowed_mimes', []))));
        if (! in_array($mime, $allAllowedMimes, true)) {
            throw BusinessLogicException::ruleViolation('Tipo de archivo no permitido.');
        }

        $allAllowedExtensions = array_unique(array_merge(...array_values(config('files.allowed_extensions', []))));
        if (! in_array($clientExtension, $allAllowedExtensions, true)) {
            throw BusinessLogicException::ruleViolation('Extensión de archivo no permitida.');
        }
    }

    /**
     * Reprocesa la imagen (JPEG, PNG, WebP) con GD si `files.image_reencode.enabled`: se decodifica y se vuelve a
     * codificar, así se pierde cualquier carga escondida en la imagen (archivo políglota) y sus metadatos EXIF
     * (ubicación GPS, cámara). Solo la subida normal: la directa a S3 no pasa los bytes por la API. Los GIF no se
     * tocan (se perdería la animación).
     *
     * @throws BusinessLogicException si la imagen no se puede decodificar o tiene demasiados píxeles
     */
    protected function reencodeImage(UploadedFile $file, string $mime): void
    {
        if (! (bool) config('files.image_reencode.enabled', false)
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! function_exists('imagecreatefromstring')) {
            return;
        }

        $path = (string) $file->getRealPath();
        $info = @getimagesize($path);
        $maxPixels = (int) config('files.image_reencode.max_megapixels', 40) * 1000000;

        // Se mira antes de decodificar: GD reserva ~4 bytes por píxel y una imagen gigante agotaría la memoria.
        if ($info === false || $maxPixels < $info[0] * $info[1]) {
            throw BusinessLogicException::ruleViolation('La imagen está dañada o es demasiado grande para procesarla.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw BusinessLogicException::ruleViolation('La imagen está dañada o es demasiado grande para procesarla.');
        }

        $quality = (int) config('files.image_reencode.quality', 90);

        imagesavealpha($image, true); // conserva la transparencia de PNG/WebP

        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, $path, $quality),
                'image/png' => imagepng($image, $path),
                'image/webp' => imagewebp($image, $path, $quality),
            };
        } finally {
            imagedestroy($image);
        }

        if (! $ok) {
            throw new \RuntimeException('No se pudo reprocesar la imagen.');
        }
    }

    /**
     * Extensión definitiva: la que corresponde al contenido detectado. Se respeta la del
     * cliente solo si es una de las extensiones válidas para ese contenido.
     */
    protected function resolveExtension(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $client = strtolower($file->getClientOriginalExtension());
        $candidates = MimeTypes::getDefault()->getExtensions($mime);

        if ($mime === 'text/plain' && in_array($client, ['txt', 'csv'], true)) {
            return $client;
        }

        if ($client !== '' && in_array($client, $candidates, true)) {
            return $client;
        }

        return $candidates[0] ?? $client;
    }

    /**
     * Generar nombre único y no adivinable; no incluye nada del nombre original.
     */
    protected function generateFilename(UploadedFile $file, ?string $extension = null): string
    {
        $extension = $extension ?? $this->resolveExtension($file);
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension));

        return strtolower((string) Str::ulid()).($extension !== '' ? ".{$extension}" : '');
    }
}
