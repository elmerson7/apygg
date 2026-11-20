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
