<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Exceptions\DirectUploadException;
use App\Exceptions\FileTooLargeForPlanException;
use App\Exceptions\StorageQuotaExceededException;
use App\Models\File;
use App\Models\FileUploadIntent;
use App\Models\User;

/**
 * Subida directa al almacenamiento con URL PUT presignada (docs/files.md §Subida directa).
 *
 * 1. `create` valida lo declarado, RESERVA cuota y devuelve la URL PUT.
 * 2. El cliente sube el contenido directo al bucket.
 * 3. `complete` comprueba el objeto REAL (existe, tamaño, tipo por contenido, checksum) y crea el `File`.
 *
 * Archivos grandes: `createMultipart` + `presignParts` + `listParts` + `complete` (une las partes y verifica igual)
 * y `abort` (libera la reserva). Todo bajo la misma intención, con la misma reserva de cuota.
 */
interface DirectUploadServiceInterface
{
    /**
     * @param  array<string, mixed>  $data  name, size, mime_type, checksum_sha256?, category?, description?, is_public?
     * @return array{intent: FileUploadIntent, upload: array{method: string, url: string, headers: array<string, mixed>, expires_at: string}}
     *
     * @throws BusinessLogicException tipo, extensión o tamaño no permitidos (422)
     * @throws DirectUploadException `direct_upload_unsupported` si el disco no firma subidas
     * @throws FileTooLargeForPlanException
     * @throws StorageQuotaExceededException
     */
    public function create(User $user, array $data): array;

    /**
     * Como `create` pero por partes (S3 multipart): reserva cuota igual y abre la subida en el proveedor.
     *
     * @param  array<string, mixed>  $data  name, size, mime_type, checksum_sha256?, category?, description?, is_public?
     * @return array{intent: FileUploadIntent, part_size: int, parts_count: int}
     *
     * @throws BusinessLogicException tipo, extensión o tamaño no permitidos (422)
     * @throws DirectUploadException `multipart_unsupported` si está apagada o el disco no es S3
     * @throws FileTooLargeForPlanException
     * @throws StorageQuotaExceededException
     */
    public function createMultipart(User $user, array $data): array;

    /**
     * URLs PUT prefirmadas de las partes pedidas (1..parts_count).
     *
     * @param  list<int>  $partNumbers
     * @return array{part_size: int, parts_count: int, expires_at: string, parts: list<array{part_number: int, url: string, headers: array<string, mixed>}>}
     *
     * @throws DirectUploadException intención cerrada, caducada o no multipart; número de parte fuera de rango
     */
    public function presignParts(FileUploadIntent $intent, array $partNumbers): array;

    /**
     * Partes ya subidas (para reanudar).
     *
     * @return array{part_size: int, parts_count: int, parts: list<array{part_number: int, size: int, etag: string}>}
     *
     * @throws DirectUploadException
     */
    public function listParts(FileUploadIntent $intent): array;

    /**
     * Aborta una intención pendiente (simple o multipart): borra lo subido y libera la reserva de cuota.
     * Idempotente si ya estaba abortada; una completada no se aborta (409).
     *
     * @throws DirectUploadException
     */
    public function abort(FileUploadIntent $intent): void;

    /**
     * Intención del tenant en contexto (404 si no existe o es de otro tenant).
     */
    public function find(string $uuid): FileUploadIntent;

    /**
     * Verifica el objeto subido y crea el `File`. Idempotente: completar de nuevo devuelve el mismo `File`.
     * Tamaño, tipo o checksum falsos descartan la intención y borran el objeto.
     *
     * @throws DirectUploadException
     */
    public function complete(FileUploadIntent $intent): File;

    /**
     * Borra los objetos y filas de las intenciones caducadas, y las cerradas más viejas que la retención.
     * Tarea de plataforma (todos los tenants). Devuelve cuántas filas borró.
     */
    public function purgeExpired(): int;
}
