<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FileService
 * 
 * Servicio centralizado para manejo de archivos: upload, delete, getUrl, exists.
 * 
 * @package App\Services
 */
class FileService
{
    /**
     * Disco de almacenamiento por defecto
     */
    protected static string $defaultDisk = 'public';

    /**
     * Subir archivo
     *
     * @param UploadedFile $file Archivo a subir
     * @param string $path Ruta donde guardar (ej: 'avatars', 'documents')
     * @param string|null $filename Nombre personalizado (null = generar automático)
     * @param string|null $disk Disco de almacenamiento (null = usar default)
     * @return array ['path' => string, 'url' => string, 'size' => int]
     */
    public static function upload(
        UploadedFile $file,
        string $path = 'uploads',
        ?string $filename = null,
        ?string $disk = null
    ): array {
        $disk = $disk ?? self::$defaultDisk;
        
        // Validar archivo
        self::validateFile($file);

        // Generar nombre único si no se proporciona
        $filename = $filename ?? self::generateFilename($file);

        // Construir ruta completa
        $fullPath = rtrim($path, '/') . '/' . $filename;

        // Guardar archivo
        $storedPath = Storage::disk($disk)->putFileAs($path, $file, $filename);

        // Obtener URL pública
        $url = Storage::disk($disk)->url($storedPath);

        return [
            'path' => $storedPath,
            'url' => $url,
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'disk' => $disk,
        ];
    }

    /**
     * Subir imagen con procesamiento
     *
     * @param UploadedFile $file Imagen a subir
     * @param string $path Ruta donde guardar
     * @param array $options Opciones: width, height, quality, format
     * @return array
     */
    public static function uploadImage(
        UploadedFile $file,
        string $path = 'images',
        array $options = []
    ): array {
        // Validar que sea imagen
        if (!str_starts_with($file->getMimeType(), 'image/')) {
            throw new \InvalidArgumentException('El archivo debe ser una imagen.');
        }

        $width = $options['width'] ?? null;
        $height = $options['height'] ?? null;
        $quality = $options['quality'] ?? 90;
        $format = $options['format'] ?? null; // jpg, png, webp

        // Procesar imagen si se especifican dimensiones
        if ($width || $height) {
            // Verificar si Intervention Image está disponible
            if (!class_exists(\Intervention\Image\Facades\Image::class)) {
                throw new \RuntimeException('Intervention Image package is required for image processing.');
            }
            
            $image = \Intervention\Image\Facades\Image::make($file);
            
            if ($width && $height) {
                $image->fit($width, $height);
            } elseif ($width) {
                $image->resize($width, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
            } elseif ($height) {
                $image->resize(null, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }

            // Cambiar formato si se especifica
            if ($format) {
                $image->encode($format, $quality);
            }

            // Generar nombre único
            $extension = $format ?? $file->getClientOriginalExtension();
            $filename = self::generateFilename($file, $extension);

            // Guardar imagen procesada
            $fullPath = rtrim($path, '/') . '/' . $filename;
            $disk = self::$defaultDisk;
            
            Storage::disk($disk)->put($fullPath, $image->stream());
            
            $url = Storage::disk($disk)->url($fullPath);

            return [
                'path' => $fullPath,
                'url' => $url,
                'filename' => $filename,
                'size' => strlen($image->stream()->getContents()),
                'mime_type' => $image->mime(),
                'width' => $image->width(),
                'height' => $image->height(),
                'disk' => $disk,
            ];
        }

        // Si no hay procesamiento, usar upload normal
        return self::upload($file, $path);
    }

    /**
     * Eliminar archivo
     *
     * @param string $path Ruta del archivo
     * @param string|null $disk Disco de almacenamiento
     * @return bool
     */
    public static function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->delete($path);
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to delete file', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obtener URL pública del archivo
     *
     * @param string $path Ruta del archivo
     * @param string|null $disk Disco de almacenamiento
     * @return string|null
     */
    public static function getUrl(string $path, ?string $disk = null): ?string
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->url($path);
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get file URL', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verificar si archivo existe
     *
     * @param string $path Ruta del archivo
     * @param string|null $disk Disco de almacenamiento
     * @return bool
     */
    public static function exists(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Obtener tamaño del archivo
     *
     * @param string $path Ruta del archivo
     * @param string|null $disk Disco de almacenamiento
     * @return int|null Tamaño en bytes
     */
    public static function getSize(string $path, ?string $disk = null): ?int
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->size($path);
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validar archivo antes de subir
     *
     * @param UploadedFile $file
     * @return void
     * @throws \InvalidArgumentException
     */
    protected static function validateFile(UploadedFile $file): void
    {
        // Validar tamaño máximo (10MB por defecto)
        $maxSize = config('filesystems.max_file_size', 10485760); // 10MB
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('El archivo excede el tamaño máximo permitido.');
        }

        // Validar tipo MIME permitido
        $allowedMimes = config('filesystems.allowed_mimes', [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        // Validar extensión
        $allowedExtensions = config('filesystems.allowed_extensions', [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx',
        ]);

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException('Extensión de archivo no permitida.');
        }
    }

    /**
     * Generar nombre único para archivo
     *
     * @param UploadedFile $file
     * @param string|null $extension Extensión personalizada
     * @return string
     */
    protected static function generateFilename(UploadedFile $file, ?string $extension = null): string
    {
        $extension = $extension ?? $file->getClientOriginalExtension();
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);

        return "{$baseName}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Obtener información del archivo
     *
     * @param string $path
     * @param string|null $disk
     * @return array|null
     */
    public static function getInfo(string $path, ?string $disk = null): ?array
    {
        $disk = $disk ?? self::$defaultDisk;

        if (!self::exists($path, $disk)) {
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
     *
     * @param string $fromPath Ruta origen
     * @param string $toPath Ruta destino
     * @param string|null $disk Disco de almacenamiento
     * @return bool
     */
    public static function copy(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            return Storage::disk($disk)->copy($fromPath, $toPath);
        } catch (\Exception $e) {
            Log::error('Failed to copy file', [
                'from' => $fromPath,
                'to' => $toPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Mover archivo
     *
     * @param string $fromPath Ruta origen
     * @param string $toPath Ruta destino
     * @param string|null $disk Disco de almacenamiento
     * @return bool
     */
    public static function move(string $fromPath, string $toPath, ?string $disk = null): bool
    {
        $disk = $disk ?? self::$defaultDisk;

        try {
            return Storage::disk($disk)->move($fromPath, $toPath);
        } catch (\Exception $e) {
            Log::error('Failed to move file', [
                'from' => $fromPath,
                'to' => $toPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
