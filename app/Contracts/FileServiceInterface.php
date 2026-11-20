<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Exceptions\FileTooLargeForPlanException;
use App\Exceptions\PublicSharingDisabledException;
use App\Exceptions\StorageQuotaExceededException;
use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;

/**
 * FileServiceInterface
 *
 * Contrato del servicio de archivos (subida, URLs y operaciones sobre discos).
 */
interface FileServiceInterface
{
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
    public function upload(UploadedFile $file,
        string $path = 'uploads',
        ?string $filename = null,
        ?string $disk = null,
        bool $isPublic = true): array;

    /**
     * Ruta de almacenamiento de una clave nueva: `[<prefijo de entorno>/][tenants/<id>/]<ruta>`.
     */
    public function tenantStoragePath(string $path): string;

    /**
     * Prefijo de entorno saneado (`files.prefix`); vacío = sin prefijo.
     */
    public function storagePrefix(): string;

    /**
     * Rechaza un archivo mayor que `max_file_mb` del plan del tenant.
     *
     * @throws FileTooLargeForPlanException 413 `file_too_large_for_plan`
     */
    public function ensureFileSizeAllowed(int $bytes): void;

    /**
     * Ejecuta `$create` solo si `$bytes` caben en la cuota `max_storage_mb` del plan, contando y creando
     * en una transacción con el tenant bloqueado (dos subidas simultáneas no pueden pasarse).
     *
     * @template T
     *
     * @param  \Closure(): T  $create
     * @return T
     *
     * @throws StorageQuotaExceededException 403 `storage_quota_exceeded`
     */
    public function withStorageQuota(int $bytes, \Closure $create): mixed;

    /**
     * Bytes usados por el tenant en contexto (archivos + reservas vigentes). Fiable solo bajo `withStorageQuota`.
     */
    public function usedStorageBytes(): int;

    /**
     * Uso y límites de almacenamiento del tenant en contexto (`null` = ilimitado).
     *
     * @return array{used_bytes: int, limit_bytes: int|null, remaining_bytes: int|null, max_file_bytes: int|null}
     */
    public function storageUsage(): array;

    /**
     * Disco configurado para archivos públicos o privados.
     */
    public function diskFor(bool $isPublic): string;

    /**
     * Mover un archivo entre discos (cambio de visibilidad) conservando su ruta.
     * Copia, y solo si la copia se completó borra el original.
     *
     * @throws \RuntimeException
     */
    public function transfer(string $path, string $fromDisk, string $toDisk, bool $toPublic): void;

    /**
     * Actualiza el registro y, si cambia la visibilidad, mueve el archivo al disco público o
     * privado. Si algo falla, el archivo vuelve a su disco original (consistencia BD/disco).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function updateWithVisibility(File $file, array $data, ?bool $makePublic): void;

    /**
     * Aplica `security.allow_public_sharing`: hacer público un archivo exige que el ajuste esté activo
     * (nivel tenant si lo hay, si no plataforma). Privado siempre se permite.
     *
     * @throws PublicSharingDisabledException
     */
    public function assertVisibilityAllowed(bool $isPublic): void;

    /**
     * URL temporal firmada para descargar un archivo privado.
     *
     * S3/MinIO: URL prefirmada del proveedor. Disco local: ruta firmada de Laravel
     * (`files.signed`), que expira y no necesita sesión.
     */
    public function temporaryDownloadUrl(File $file, ?int $minutes = null): ?string;

    /**
     * Nombre de descarga seguro (sin comillas ni saltos de línea ni rutas).
     */
    public function safeDownloadName(File $file): string;

    /**
     * Tipo de archivo según su MIME (image, video, audio, archive, document, other).
     */
    public function detectType(string $mimeType): string;

    /**
     * Subir imagen con procesamiento opcional (resize/formato)
     *
     * @param  array<string, mixed>  $options  width, height, quality, format
     * @return array<string, mixed>
     */
    public function uploadImage(UploadedFile $file,
        string $path = 'images',
        array $options = []): array;

    /**
     * Eliminar archivo
     */
    public function delete(string $path, ?string $disk = null): bool;

    /**
     * Obtener URL pública del archivo.
     * Para S3/Minio construye la URL explícitamente usando buildS3Url().
     */
    public function getUrl(string $path, ?string $disk = null): ?string;

    /**
     * Obtener URL temporal con expiración (para archivos privados S3/Minio).
     *
     * @param  int  $minutes  Minutos de expiración (default 720 = 12h)
     */
    public function getTemporaryUrl(string $path, ?string $disk = null, int $minutes = 720): ?string;

    /**
     * Verificar si archivo existe
     */
    public function exists(string $path, ?string $disk = null): bool;

    /**
     * Obtener tamaño del archivo en bytes
     */
    public function getSize(string $path, ?string $disk = null): ?int;

    /**
     * Obtener información del archivo
     *
     * @return array<string, mixed>
     */
    public function getInfo(string $path, ?string $disk = null): ?array;

    /**
     * Copiar archivo
     */
    public function copy(string $fromPath, string $toPath, ?string $disk = null): bool;

    /**
     * Mover archivo
     */
    public function move(string $fromPath, string $toPath, ?string $disk = null): bool;
}
