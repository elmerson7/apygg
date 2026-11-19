<?php

namespace App\Contracts;

/**
 * BackupServiceInterface
 *
 * Contrato del servicio de respaldos de base de datos.
 */
interface BackupServiceInterface
{
    /**
     * Crear backup de base de datos
     *
     * @param  bool  $compress  Comprimir el backup
     * @param  bool  $uploadToRemote  Subir a almacenamiento remoto
     * @return array<string, mixed> Información del backup creado
     */
    public function createDatabaseBackup(bool $compress = true, bool $uploadToRemote = true): array;

    /**
     * Subir backup a almacenamiento remoto (S3/MinIO)
     *
     * @param  string  $localPath  Ruta local del archivo
     * @param  string  $filename  Nombre del archivo
     * @return string Ruta remota del archivo
     *
     * @throws \Exception Si falla la subida
     */
    public function uploadToRemote(string $localPath, string $filename): string;

    /**
     * Listar backups disponibles
     *
     * @param  string|null  $type  Tipo de backup ('database', 'files', null para todos)
     * @param  bool  $includeRemote  Incluir backups remotos
     * @return list<array<string, mixed>> Lista de backups
     */
    public function listBackups(?string $type = null, bool $includeRemote = false): array;

    /**
     * Restaurar backup de base de datos
     *
     * @param  string  $backupPath  Ruta del backup a restaurar
     * @param  bool  $confirm  Confirmación requerida
     * @return bool True si se restauró exitosamente
     */
    public function restoreDatabaseBackup(string $backupPath, bool $confirm = true): bool;

    /**
     * Limpiar backups antiguos según política de retención
     *
     * @return array<string, mixed> Estadísticas de limpieza
     */
    public function cleanOldBackups(): array;
}
