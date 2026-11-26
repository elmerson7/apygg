<?php

namespace App\Contracts;

use App\Enums\FileScanStatus;
use App\Exceptions\FileNotAvailableException;
use App\Models\File;

/**
 * Escaneo antivirus de los archivos subidos (docs/files.md, "Escaneo antivirus"). Apagado por defecto
 * (`FILE_SCAN_ENABLED=false`): los archivos nacen `skipped` y se sirven como siempre. Encendido, nacen `pending`
 * (cuarentena: sin URL ni descarga), un job los escanea con los motores de `files.scanning.drivers` y pasan a
 * `clean`, `infected` (se borra el contenido) o `failed` (sigue en cuarentena).
 */
interface FileScanServiceInterface
{
    public function enabled(): bool;

    /** Estado con el que nace un archivo nuevo: `pending` con el escaneo encendido, `skipped` si no. */
    public function initialStatus(): FileScanStatus;

    /**
     * Escanea el archivo si está `pending` o `failed` y guarda el resultado. Devuelve el estado final.
     * Un fallo pasajero de un motor lanza (para que el job reintente) y deja el archivo como estaba.
     *
     * @throws \RuntimeException
     */
    public function scan(File $file): FileScanStatus;

    /**
     * Vuelve a encolar el escaneo de los archivos con esos estados (`pending`, `failed` y, para revisar lo subido antes
     * de encender el escaneo, `skipped`: pasa a `pending` y queda en cuarentena hasta escanearse). Todos los tenants;
     * cada job se encola con el tenant de su archivo. Devuelve cuántos encoló.
     *
     * @param  list<FileScanStatus>  $statuses
     */
    public function requeue(array $statuses, int $limit): int;

    /** Marca como `failed` un archivo que agotó los reintentos (sigue en cuarentena). */
    public function markFailed(File $file, string $reason): void;

    /**
     * @throws FileNotAvailableException si el archivo está en cuarentena o infectado
     */
    public function assertAvailable(File $file): void;
}
