<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El archivo existe pero no se puede descargar ni enlazar por el escaneo antivirus (docs/files.md, "Escaneo antivirus").
 * Códigos: `file_scan_pending` (409, en cuarentena: reintenta luego), `file_scan_failed` (409, no se pudo escanear) y
 * `file_infected` (410, malware detectado: el contenido se borró).
 */
class FileNotAvailableException extends ApiException
{
    private function __construct(string $message, int $status, private readonly string $errorCodeValue)
    {
        parent::__construct($message, $status);
    }

    public static function pending(): self
    {
        return new self('El archivo se está revisando con el antivirus: inténtalo de nuevo en unos instantes.', 409, 'file_scan_pending');
    }

    public static function failed(): self
    {
        return new self('No se pudo revisar el archivo con el antivirus: sigue en cuarentena.', 409, 'file_scan_failed');
    }

    public static function infected(): self
    {
        return new self('El antivirus detectó malware en el archivo y se eliminó su contenido.', 410, 'file_infected');
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->errorCodeValue;
    }
}
