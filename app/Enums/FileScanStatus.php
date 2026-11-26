<?php

namespace App\Enums;

/**
 * Estado del escaneo antivirus de un archivo (docs/files.md, "Escaneo antivirus"). Solo `skipped` y `clean` se
 * pueden descargar o tener URL: el resto está en cuarentena.
 */
enum FileScanStatus: string
{
    /** Subido con el escaneo apagado (o antes de que existiera). Se sirve como siempre. */
    case Skipped = 'skipped';
    /** En cola para escanear: en cuarentena. */
    case Pending = 'pending';
    case Clean = 'clean';
    /** Malware detectado: el contenido se borró del almacenamiento; la fila queda como registro. */
    case Infected = 'infected';
    /** No se pudo escanear (servicio caído tras los reintentos, archivo demasiado grande): sigue en cuarentena. */
    case Failed = 'failed';

    public function isAvailable(): bool
    {
        return $this === self::Skipped || $this === self::Clean;
    }
}
