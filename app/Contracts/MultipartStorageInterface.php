<?php

namespace App\Contracts;

/**
 * Operaciones de subida por partes (S3 multipart) sobre un disco de Laravel. Aísla el SDK del proveedor:
 * `DirectUploadService` solo habla con esta interfaz y los tests la sustituyen por una falsa.
 */
interface MultipartStorageInterface
{
    /** ¿El disco puede hacer subida por partes (driver S3)? */
    public function supports(string $disk): bool;

    /** Abre la subida y devuelve su `upload_id`. */
    public function initiate(string $disk, string $path, string $mimeType): string;

    /**
     * URL PUT prefirmada de una parte (1..10000).
     *
     * @return array{url: string, headers: array<string, mixed>}
     */
    public function presignPart(string $disk, string $path, string $uploadId, int $partNumber, \DateTimeInterface $expiresAt): array;

    /**
     * Partes ya subidas, por número. `null` si el proveedor ya no conoce la subida (completada o abortada).
     *
     * @return array<int, array{etag: string, size: int}>|null
     */
    public function listParts(string $disk, string $path, string $uploadId): ?array;

    /**
     * Une las partes en el objeto final.
     *
     * @param  array<int, string>  $etags  número de parte => ETag
     */
    public function complete(string $disk, string $path, string $uploadId, array $etags): void;

    /** Aborta la subida y libera las partes. Idempotente: si ya no existe, no falla. */
    public function abort(string $disk, string $path, string $uploadId): void;
}
