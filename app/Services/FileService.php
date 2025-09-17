<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use App\Jobs\ScanFileForVirus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Carbon\Carbon;

class FileService
{
    /**
     * Genera una URL presigned para upload
     */
    public function generateUploadUrl(
        string $type,
        string $originalName,
        string $mimeType,
        int $size,
        ?User $user = null,
        array $meta = []
    ): array {
        // Validar parámetros
        $this->validateUploadParameters($type, $originalName, $mimeType, $size);

        // Generar información del archivo
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $path = File::generatePath($type, $extension, $user?->id);
        $disk = File::getDiskForType($type);
        $fileId = Str::ulid();

        // Crear registro en base de datos
        $file = File::create([
            'id' => $fileId,
            'user_id' => $user?->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'checksum' => '', // Se calculará después del upload
            'visibility' => $type === File::TYPE_AVATAR ? File::VISIBILITY_PUBLIC : File::VISIBILITY_PRIVATE,
            'status' => File::STATUS_UPLOADING,
            'meta' => array_merge($meta, [
                'type' => $type,
                'upload_initiated_at' => now()->toISOString(),
            ]),
        ]);

        // Generar URL presigned
        $expiration = Carbon::now()->addMinutes(15);
        $storage = Storage::disk($disk);
        
        $presignedUrl = $storage->temporaryUploadUrl(
            $path,
            $expiration,
            [
                'ContentType' => $mimeType,
                'ContentLength' => $size,
            ]
        );

        return [
            'file_id' => $fileId,
            'upload_url' => $presignedUrl,
            'expires_at' => $expiration->toISOString(),
            'path' => $path,
            'disk' => $disk,
            'meta' => [
                'method' => 'PUT',
                'headers' => [
                    'Content-Type' => $mimeType,
                    'Content-Length' => $size,
                ],
            ],
        ];
    }

    /**
     * Confirma que el upload fue exitoso y procesa el archivo
     */
    public function confirmUpload(string $fileId, ?string $checksum = null): File
    {
        $file = File::findOrFail($fileId);

        if ($file->status !== File::STATUS_UPLOADING) {
            throw new \InvalidArgumentException('File is not in uploading status');
        }

        // Verificar que el archivo existe en storage
        if (!$file->existsInStorage()) {
            $file->markAsFailed();
            throw new \RuntimeException('File was not found in storage');
        }

        // Calcular checksum si no se proporciona
        if (!$checksum) {
            $content = Storage::disk($file->disk)->get($file->path);
            $checksum = hash('sha256', $content);
        }

        // Actualizar archivo con checksum
        $file->update([
            'checksum' => $checksum,
            'status' => config('files.antivirus_enabled', false) ? File::STATUS_SCANNING : File::STATUS_VERIFIED,
            'meta' => array_merge($file->meta ?? [], [
                'upload_completed_at' => now()->toISOString(),
            ]),
        ]);

        // Programar escaneo antivirus si está habilitado
        if (config('files.antivirus_enabled', false)) {
            ScanFileForVirus::dispatch($file);
        }

        return $file->fresh();
    }

    /**
     * Genera URL de descarga temporal
     */
    public function generateDownloadUrl(File $file, int $expiration = 3600): string
    {
        if ($file->status !== File::STATUS_VERIFIED) {
            throw new \InvalidArgumentException('File is not verified');
        }

        return $file->getUrl($expiration);
    }

    /**
     * Sube un archivo directamente (sin presigned URL)
     */
    public function uploadDirect(
        UploadedFile $uploadedFile,
        string $type,
        ?User $user = null,
        array $meta = []
    ): File {
        // Validar archivo
        $this->validateUploadedFile($uploadedFile, $type);

        // Generar información del archivo
        $originalName = $uploadedFile->getClientOriginalName();
        $mimeType = $uploadedFile->getMimeType();
        $size = $uploadedFile->getSize();
        $extension = $uploadedFile->getClientOriginalExtension();
        
        $path = File::generatePath($type, $extension, $user?->id);
        $disk = File::getDiskForType($type);
        $fileId = Str::ulid();

        // Calcular checksum
        $checksum = hash_file('sha256', $uploadedFile->getRealPath());

        // Subir archivo
        $uploaded = Storage::disk($disk)->putFileAs(
            dirname($path),
            $uploadedFile,
            basename($path)
        );

        if (!$uploaded) {
            throw new \RuntimeException('Failed to upload file');
        }

        // Crear registro en base de datos
        $file = File::create([
            'id' => $fileId,
            'user_id' => $user?->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'checksum' => $checksum,
            'visibility' => $type === File::TYPE_AVATAR ? File::VISIBILITY_PUBLIC : File::VISIBILITY_PRIVATE,
            'status' => config('files.antivirus_enabled', false) ? File::STATUS_SCANNING : File::STATUS_VERIFIED,
            'meta' => array_merge($meta, [
                'type' => $type,
                'uploaded_at' => now()->toISOString(),
            ]),
        ]);

        // Programar escaneo antivirus si está habilitado
        if (config('files.antivirus_enabled', false)) {
            ScanFileForVirus::dispatch($file);
        }

        return $file;
    }

    /**
     * Elimina un archivo
     */
    public function deleteFile(File $file): bool
    {
        $deleted = $file->deleteFromStorage();
        
        if ($deleted) {
            $file->delete();
        }

        return $deleted;
    }

    /**
     * Mueve archivo a cuarentena
     */
    public function quarantineFile(File $file): bool
    {
        $moved = $file->moveToQuarantine();
        
        if ($moved) {
            $file->markAsInfected();
        }

        return $moved;
    }

    /**
     * Limpia archivos temporales antiguos
     */
    public function cleanupOldTempFiles(): int
    {
        $cutoffDate = Carbon::now()->subHours(24);
        
        $oldTempFiles = File::where('disk', 's3_temp')
            ->where('created_at', '<', $cutoffDate)
            ->whereIn('status', [File::STATUS_UPLOADING, File::STATUS_FAILED])
            ->get();

        $deletedCount = 0;
        
        foreach ($oldTempFiles as $file) {
            if ($this->deleteFile($file)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * Limpia archivos infectados antiguos
     */
    public function cleanupOldInfectedFiles(): int
    {
        $cutoffDate = Carbon::now()->subDays(30);
        
        $oldInfectedFiles = File::where('status', File::STATUS_INFECTED)
            ->where('updated_at', '<', $cutoffDate)
            ->get();

        $deletedCount = 0;
        
        foreach ($oldInfectedFiles as $file) {
            if ($this->deleteFile($file)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * Valida parámetros de upload
     */
    private function validateUploadParameters(string $type, string $originalName, string $mimeType, int $size): void
    {
        $validator = Validator::make([
            'type' => $type,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
        ], [
            'type' => 'required|in:' . implode(',', [File::TYPE_AVATAR, File::TYPE_DOCUMENT, File::TYPE_GENERAL, File::TYPE_TEMP]),
            'original_name' => 'required|string|max:255',
            'mime_type' => 'required|string',
            'size' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid upload parameters: ' . implode(', ', $validator->errors()->all()));
        }

        // Validar MIME type
        if (!File::isValidMimeForType($mimeType, $type)) {
            throw new \InvalidArgumentException("MIME type {$mimeType} is not allowed for file type {$type}");
        }

        // Validar tamaño
        $maxSize = $this->getMaxSizeForType($type);
        if ($size > $maxSize) {
            throw new \InvalidArgumentException("File size {$size} exceeds maximum allowed size {$maxSize} for type {$type}");
        }
    }

    /**
     * Valida archivo subido
     */
    private function validateUploadedFile(UploadedFile $file, string $type): void
    {
        $validator = Validator::make([
            'file' => $file,
            'type' => $type,
        ], [
            'file' => 'required|file',
            'type' => 'required|in:' . implode(',', [File::TYPE_AVATAR, File::TYPE_DOCUMENT, File::TYPE_GENERAL, File::TYPE_TEMP]),
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid file: ' . implode(', ', $validator->errors()->all()));
        }

        // Validar MIME type
        $mimeType = $file->getMimeType();
        if (!File::isValidMimeForType($mimeType, $type)) {
            throw new \InvalidArgumentException("MIME type {$mimeType} is not allowed for file type {$type}");
        }

        // Validar tamaño
        $maxSize = $this->getMaxSizeForType($type);
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException("File size exceeds maximum allowed size {$maxSize} for type {$type}");
        }
    }

    /**
     * Obtiene el tamaño máximo permitido para un tipo de archivo
     */
    private function getMaxSizeForType(string $type): int
    {
        return match ($type) {
            File::TYPE_AVATAR => config('files.max_size_avatar', 2 * 1024 * 1024), // 2MB
            File::TYPE_DOCUMENT => config('files.max_size_document', 50 * 1024 * 1024), // 50MB
            default => config('files.max_size', 10 * 1024 * 1024), // 10MB
        };
    }
}
