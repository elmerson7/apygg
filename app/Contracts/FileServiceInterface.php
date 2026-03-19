<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * FileServiceInterface
 *
 * Contrato para el servicio de gestión de archivos.
 */
interface FileServiceInterface
{
    /**
     * Subir archivo
     *
     * @param  UploadedFile  $file  Archivo a subir
     * @param  string  $path  Ruta de destino (relativa al disco)
     * @param  string|null  $filename  Nombre del archivo (opcional)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return array ['path', 'url', 'filename', 'size', 'mime_type', 'disk']
     */
    public function upload(UploadedFile $file, string $path = 'uploads', ?string $filename = null, ?string $disk = null): array;

    /**
     * Subir imagen con procesamiento opcional (resize/formato)
     *
     * @param  UploadedFile  $file  Imagen a subir
     * @param  string  $path  Ruta de destino (relativa al disco)
     * @param  array  $options  Opciones de procesamiento [width, height, quality, format]
     * @return array Información del archivo subido
     */
    public function uploadImage(UploadedFile $file, string $path = 'images', array $options = []): array;

    /**
     * Eliminar archivo
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return bool True si se eliminó exitosamente
     */
    public function delete(string $path, ?string $disk = null): bool;

    /**
     * Obtener URL pública del archivo
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return string|null URL pública o null si no existe
     */
    public function getUrl(string $path, ?string $disk = null): ?string;

    /**
     * Obtener URL temporal con expiración (para archivos privados S3/Minio)
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @param  int  $minutes  Minutos de expiración (default 720 = 12h)
     * @return string|null URL temporal o null si no existe
     */
    public function getTemporaryUrl(string $path, ?string $disk = null, int $minutes = 720): ?string;

    /**
     * Verificar si archivo existe
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return bool True si existe
     */
    public function exists(string $path, ?string $disk = null): bool;

    /**
     * Obtener tamaño del archivo en bytes
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return int|null Tamaño en bytes o null si no existe
     */
    public function getSize(string $path, ?string $disk = null): ?int;

    /**
     * Obtener información del archivo
     *
     * @param  string  $path  Ruta del archivo (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return array|null Información del archivo [path, url, size, mime_type, last_modified] o null si no existe
     */
    public function getInfo(string $path, ?string $disk = null): ?array;

    /**
     * Copiar archivo
     *
     * @param  string  $fromPath  Ruta origen (relativa al disco)
     * @param  string  $toPath  Ruta destino (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return bool True si se copió exitosamente
     */
    public function copy(string $fromPath, string $toPath, ?string $disk = null): bool;

    /**
     * Mover archivo
     *
     * @param  string  $fromPath  Ruta origen (relativa al disco)
     * @param  string  $toPath  Ruta destino (relativa al disco)
     * @param  string|null  $disk  Disco de almacenamiento (opcional)
     * @return bool True si se movió exitosamente
     */
    public function move(string $fromPath, string $toPath, ?string $disk = null): bool;
}