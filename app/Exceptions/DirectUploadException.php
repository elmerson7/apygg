<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Fallo de la subida directa presignada. Cada causa tiene su `code` estable:
 * `direct_upload_unsupported`, `upload_object_missing`, `upload_size_mismatch`, `upload_mime_mismatch`,
 * `upload_checksum_mismatch`, `upload_intent_expired`, `upload_intent_aborted`; y de la subida por partes:
 * `multipart_unsupported`, `upload_parts_missing`, `upload_part_mismatch`, `upload_intent_completed`, `upload_storage_error`.
 */
class DirectUploadException extends ApiException
{
    private function __construct(string $message, int $status, private readonly string $errorCodeValue)
    {
        parent::__construct($message, $status);
    }

    public static function unsupported(string $disk): self
    {
        return new self("El disco '{$disk}' no admite subida directa presignada.", 422, 'direct_upload_unsupported');
    }

    public static function objectMissing(): self
    {
        return new self('El archivo aún no está en el almacenamiento: sube el contenido a la URL y vuelve a completar.', 422, 'upload_object_missing');
    }

    public static function sizeMismatch(int $declared, int $actual): self
    {
        return new self("El tamaño real ({$actual} bytes) no coincide con el declarado ({$declared}).", 422, 'upload_size_mismatch');
    }

    public static function mimeMismatch(string $declared, string $detected): self
    {
        return new self("El contenido real ({$detected}) no coincide con el tipo declarado ({$declared}).", 422, 'upload_mime_mismatch');
    }

    public static function checksumMismatch(): self
    {
        return new self('El checksum del contenido no coincide con el declarado.', 422, 'upload_checksum_mismatch');
    }

    public static function expired(): self
    {
        return new self('La intención de subida caducó: crea una nueva.', 422, 'upload_intent_expired');
    }

    public static function aborted(): self
    {
        return new self('La intención de subida fue descartada: crea una nueva.', 422, 'upload_intent_aborted');
    }

    public static function multipartUnsupported(string $reason): self
    {
        return new self($reason, 422, 'multipart_unsupported');
    }

    /** @param list<int> $missing */
    public static function partsMissing(array $missing, int $expected): self
    {
        $shown = implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? '...' : '');

        return new self("Faltan partes por subir ({$shown}) de {$expected}: súbelas y vuelve a completar.", 422, 'upload_parts_missing');
    }

    public static function partMismatch(string $detail): self
    {
        return new self("Las partes subidas no coinciden con lo declarado: {$detail}.", 422, 'upload_part_mismatch');
    }

    public static function completed(): self
    {
        return new self('La subida ya se completó: no admite más cambios ni se puede abortar (borra el archivo si ya no lo quieres).', 409, 'upload_intent_completed');
    }

    public static function storageError(): self
    {
        return new self('El almacenamiento no pudo completar la operación: reintenta en unos instantes.', 502, 'upload_storage_error');
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->errorCodeValue;
    }
}
