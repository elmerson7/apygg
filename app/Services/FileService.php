<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FileService
 *
 * Servicio centralizado para manejo de archivos: upload, delete, getUrl, exists.
 */
class FileService
{
    protected static string $defaultDisk = 'public';

    /**
     * Subir archivo
     *
     * @return array ['path', 'url', 'filename', 'size', 'mime_type', 'disk']
     */
    public static function upload(
        UploadedFile $file,
        string $path = 'uploads',
        ?string $filename = null,
        ?string $disk = null
    ): array {
        $disk = $disk ?? self::$defaultDisk;
        self::validateFile($file);
        $filename = $filename ?? self::generateFilename($file);
        $storedPath = Storage::disk($disk)->putFileAs($path, $file, $filename);

        // S3/Minio: marcar como público
        if ($disk === 's3') {
            try {
                Storage::disk($disk)->setVisibility($storedPath, 'public');
            } catch (\Throwable $e) {
                LogService::warning('No se pudo establecer visibilidad pública en S3/Minio', [
                    'path' => $storedPath,
                    'error' => $e->getMessage(),
                ], 'files');
            }
        }

        LogService::info('Archivo subido', [
            'path' => $storedPath,
            'disk' => $disk,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ], 'files');

        return [
            'path' => $storedPath,
            'url' => self::getUrl($storedPath, $disk),
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'disk' => $disk,
        ];
    }

    /**
     * Subir imagen con procesamiento opcional (resize/formato)
     *
     * @param  array  $options  width, height, quality, format
     */
    public static function uploadImage(
        UploadedFile $file,
        string $path = 'images',
        array $options = []
    ): array {
        if (! str_starts_with($file->getMimeType(), 'image/')) {
            throw new \InvalidArgumentException('El archivo debe ser una imagen.');
        }

        $width = $options['width'] ?? null;
        $height = $options['height'] ?? null;
        $quality = $options['quality'] ?? 90;
        $format = $options['format'] ?? null;

        if ($width || $height) {
            if (! class_exists(\Intervention\Image\Facades\Image::class)) {
                throw new \RuntimeException('Intervention Image package is required for image processing.');
            }

            $image = \Intervention\Image\Facades\Image::make($file);

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
            $filename = self::generateFilename($file, $extension);
            $fullPath = rtrim($path, '/').'/'.$filename;
            $disk = self::$defaultDisk;

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

        return self::upload($file, $path);
    }

    /**
     * Eliminar archivo
     */
    public static function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

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
    public static function getUrl(string $path, ?string $disk = null): ?string
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            if ($disk === 's3') {
                return self::buildS3Url($path);
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
    public static function getTemporaryUrl(string $path, ?string $disk = null, int $minutes = 720): ?string
    {
        $disk = $disk ?? self::$defaultDisk;

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
    protected static function buildS3Url(string $path): string
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
    public static function exists(string $path, ?string $disk = null): bool
    {
        return Storage::disk($disk ?? self::$defaultDisk)->exists($path);
    }

    /**
     * Obtener tamaño del archivo en bytes
     */
    public static function getSize(string $path, ?string $disk = null): ?int
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->size($path) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener información del archivo
     */
    public static function getInfo(string $path, ?string $disk = null): ?array
    {
        $disk = $disk ?? self::$defaultDisk;
        if (! self::exists($path, $disk)) {
            return null;
        }

        return [
            'path' => $path,
            'url' => self::getUrl($path, $disk),
            'size' => self::getSize($path, $disk),
            'mime_type' => Storage::disk($disk)->mimeType($path),
            'last_modified' => Storage::disk($disk)->lastModified($path),
        ];
    }

    /**
     * Copiar archivo
     */
    public static function copy(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

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
    public static function move(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

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
     * Validar archivo antes de subir
     *
     * @throws \InvalidArgumentException
     */
    protected static function validateFile(UploadedFile $file): void
    {
        $maxSize = config('files.max_sizes.default', 10 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('El archivo excede el tamaño máximo permitido.');
        }

        $allAllowedMimes = array_unique(array_merge(...array_values(config('files.allowed_mimes', []))));
        if (empty($allAllowedMimes)) {
            $allAllowedMimes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
        }
        if (! in_array($file->getMimeType(), $allAllowedMimes)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        $allAllowedExtensions = array_unique(array_merge(...array_values(config('files.allowed_extensions', []))));
        if (empty($allAllowedExtensions)) {
            $allAllowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allAllowedExtensions)) {
            throw new \InvalidArgumentException('Extensión de archivo no permitida.');
        }
    }

    /**
     * Generar nombre único para archivo
     */
    protected static function generateFilename(UploadedFile $file, ?string $extension = null): string
    {
        $extension = $extension ?? $file->getClientOriginalExtension();
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return "{$baseName}_".now()->format('YmdHis').'_'.Str::random(8).".$extension";
    }
}
