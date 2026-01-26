<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * BackupService
 *
 * Servicio centralizado para operaciones de backup.
 * Maneja creación, restauración, listado y retención de backups.
 */
class BackupService
{
    /**
     * Crear backup de base de datos
     *
     * @param  bool  $compress  Comprimir el backup
     * @param  bool  $uploadToRemote  Subir a almacenamiento remoto
     * @return array Información del backup creado
     */
    public static function createDatabaseBackup(bool $compress = true, bool $uploadToRemote = true): array
    {
        $config = config('backups');
        $connection = config("database.connections.{$config['database']['connection']}");

        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];
        $host = $connection['host'];
        $port = $connection['port'] ?? 5432;

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "backup_db_{$database}_{$timestamp}.sql";
        $localPath = $config['local_path'];
        $backupPath = "{$localPath}/{$filename}";

        // Crear directorio si no existe
        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        // Comando pg_dump
        $command = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -F c -f %s',
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($backupPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Error al ejecutar pg_dump: '.implode("\n", $output));
        }

        $finalPath = $backupPath;
        $finalFilename = $filename;

        // Comprimir si está habilitado
        if ($compress && $config['compression']['enabled']) {
            $compressedPath = $backupPath.'.gz';
            exec("gzip -c {$backupPath} > {$compressedPath}", $compressOutput, $compressReturnCode);

            if ($compressReturnCode === 0) {
                unlink($backupPath);
                $finalPath = $compressedPath;
                $finalFilename = $filename.'.gz';
            } else {
                Log::warning('Error al comprimir backup, manteniendo sin comprimir', [
                    'error' => implode("\n", $compressOutput),
                ]);
            }
        }

        $fileSize = filesize($finalPath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);

        $backupInfo = [
            'filename' => $finalFilename,
            'path' => $finalPath,
            'size' => $fileSize,
            'size_mb' => $fileSizeMB,
            'type' => 'database',
            'created_at' => now()->toIso8601String(),
            'compressed' => $compress && $config['compression']['enabled'],
            'remote_uploaded' => false,
        ];

        // Intentar subir a almacenamiento remoto (S3/MinIO)
        // Si falla, el backup se mantiene en storage local
        if ($uploadToRemote && $config['remote']['enabled']) {
            try {
                $remotePath = self::uploadToRemote($finalPath, $finalFilename);
                $backupInfo['remote_path'] = $remotePath;
                $backupInfo['remote_uploaded'] = true;
                Log::info('Backup subido exitosamente a almacenamiento remoto', [
                    'backup' => $finalFilename,
                    'remote_path' => $remotePath,
                ]);
            } catch (\Exception $e) {
                // No fallar el backup completo si falla la subida remota
                // El backup queda en storage local como respaldo
                Log::warning('No se pudo subir backup a almacenamiento remoto, manteniendo en storage local', [
                    'error' => $e->getMessage(),
                    'backup' => $finalFilename,
                    'local_path' => $finalPath,
                ]);
                $backupInfo['remote_upload_error'] = $e->getMessage();
            }
        } else {
            // Si remoto no está habilitado, solo mantener en local
            Log::info('Backup guardado en storage local (remoto deshabilitado)', [
                'backup' => $finalFilename,
                'local_path' => $finalPath,
            ]);
        }

        Log::info('Backup de base de datos creado', $backupInfo);

        return $backupInfo;
    }

    /**
     * Subir backup a almacenamiento remoto (S3/MinIO)
     *
     * @param  string  $localPath  Ruta local del archivo
     * @param  string  $filename  Nombre del archivo
     * @return string Ruta remota del archivo
     *
     * @throws \Exception Si falla la subida
     */
    public static function uploadToRemote(string $localPath, string $filename): string
    {
        $config = config('backups');
        $remotePath = rtrim($config['remote']['path'], '/').'/'.$filename;
        $diskName = $config['remote']['disk'];

        // Verificar que el disco esté configurado
        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! $diskConfig) {
            throw new \RuntimeException("Disco de almacenamiento remoto '{$diskName}' no está configurado");
        }

        try {
            $disk = Storage::disk($diskName);

            // Verificar que el archivo local existe
            if (! file_exists($localPath)) {
                throw new \RuntimeException("Archivo local no encontrado: {$localPath}");
            }

            // Leer contenido del archivo
            $content = file_get_contents($localPath);
            if ($content === false) {
                throw new \RuntimeException("No se pudo leer el archivo local: {$localPath}");
            }

            // Para S3, verificar que el bucket exista
            // Nota: No podemos crear buckets automáticamente desde Laravel Storage
            // El bucket debe existir previamente en MinIO/S3
            if ($diskName === 's3' && isset($diskConfig['bucket'])) {
                try {
                    // Intentar verificar acceso al bucket listando archivos
                    $disk->files($config['remote']['path']);
                } catch (\Aws\S3\Exception\S3Exception $e) {
                    if ($e->getAwsErrorCode() === 'NoSuchBucket') {
                        throw new \RuntimeException(
                            "El bucket '{$diskConfig['bucket']}' no existe en S3/MinIO. ".
                            'Crea el bucket primero usando la consola de MinIO o AWS S3.'
                        );
                    }
                    // Otros errores: continuar e intentar subir de todas formas
                    Log::warning('No se pudo verificar acceso al bucket S3, intentando subir de todas formas', [
                        'bucket' => $diskConfig['bucket'],
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $e) {
                    // Otros errores: continuar e intentar subir de todas formas
                    Log::warning('No se pudo verificar acceso al bucket S3, intentando subir de todas formas', [
                        'bucket' => $diskConfig['bucket'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Subir a S3/MinIO
            try {
                $uploaded = $disk->put($remotePath, $content);
            } catch (\Aws\S3\Exception\S3Exception $e) {
                // Error específico de AWS S3
                $awsMessage = $e->getAwsErrorMessage() ?? $e->getMessage();
                $awsCode = $e->getAwsErrorCode() ?? 'Unknown';

                throw new \RuntimeException("Error AWS S3 [{$awsCode}]: {$awsMessage}", 0, $e);
            } catch (\Exception $e) {
                // Otros errores
                throw new \RuntimeException("Error al subir archivo: {$e->getMessage()}", 0, $e);
            }

            if (! $uploaded) {
                throw new \RuntimeException("Error al subir archivo a {$diskName}. Verificar credenciales y acceso al bucket.");
            }

            return $remotePath;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            // Error específico de AWS S3
            $message = $e->getAwsErrorMessage() ?? $e->getMessage();

            throw new \RuntimeException("Error AWS S3: {$message}", 0, $e);
        } catch (\Exception $e) {
            // Re-lanzar con contexto adicional
            throw new \RuntimeException("Error al subir backup a {$diskName}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Listar backups disponibles
     *
     * @param  string|null  $type  Tipo de backup ('database', 'files', null para todos)
     * @param  bool  $includeRemote  Incluir backups remotos
     * @return array Lista de backups
     */
    public static function listBackups(?string $type = null, bool $includeRemote = false): array
    {
        $config = config('backups');
        $backups = [];

        // Backups locales
        $localPath = $config['local_path'];
        if (is_dir($localPath)) {
            $files = glob("{$localPath}/backup_*.{sql,sql.gz}", GLOB_BRACE);

            foreach ($files as $file) {
                $filename = basename($file);
                $backupInfo = self::getBackupInfo($file, $filename, 'local');

                if ($type === null || $backupInfo['type'] === $type) {
                    $backups[] = $backupInfo;
                }
            }
        }

        // Backups remotos
        if ($includeRemote && $config['remote']['enabled']) {
            try {
                $disk = Storage::disk($config['remote']['disk']);
                $remotePath = $config['remote']['path'];
                $remoteFiles = $disk->files($remotePath);

                foreach ($remoteFiles as $remoteFile) {
                    $filename = basename($remoteFile);
                    if (preg_match('/^backup_.*\.(sql|sql\.gz)$/', $filename)) {
                        $backupInfo = self::getBackupInfoFromRemote($disk, $remoteFile, $filename);

                        if ($type === null || $backupInfo['type'] === $type) {
                            $backups[] = $backupInfo;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error al listar backups remotos', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Ordenar por fecha (más reciente primero)
        usort($backups, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return $backups;
    }

    /**
     * Obtener información de un backup local
     */
    protected static function getBackupInfo(string $filePath, string $filename, string $location): array
    {
        $fileSize = filesize($filePath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        $createdAt = Carbon::createFromTimestamp(filemtime($filePath));

        // Extraer tipo y fecha del nombre del archivo
        $type = 'database'; // Por ahora solo database
        $compressed = str_ends_with($filename, '.gz');

        return [
            'filename' => $filename,
            'path' => $filePath,
            'location' => $location,
            'size' => $fileSize,
            'size_mb' => $fileSizeMB,
            'type' => $type,
            'compressed' => $compressed,
            'created_at' => $createdAt->toIso8601String(),
            'created_at_human' => $createdAt->diffForHumans(),
        ];
    }

    /**
     * Obtener información de un backup remoto
     */
    protected static function getBackupInfoFromRemote($disk, string $remotePath, string $filename): array
    {
        $fileSize = $disk->size($remotePath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        $lastModified = $disk->lastModified($remotePath);
        $createdAt = Carbon::createFromTimestamp($lastModified);

        $type = 'database';
        $compressed = str_ends_with($filename, '.gz');

        return [
            'filename' => $filename,
            'path' => $remotePath,
            'location' => 'remote',
            'size' => $fileSize,
            'size_mb' => $fileSizeMB,
            'type' => $type,
            'compressed' => $compressed,
            'created_at' => $createdAt->toIso8601String(),
            'created_at_human' => $createdAt->diffForHumans(),
        ];
    }

    /**
     * Restaurar backup de base de datos
     *
     * @param  string  $backupPath  Ruta del backup a restaurar
     * @param  bool  $confirm  Confirmación requerida
     * @return bool True si se restauró exitosamente
     */
    public static function restoreDatabaseBackup(string $backupPath, bool $confirm = true): bool
    {
        if (! file_exists($backupPath) && ! Storage::disk('s3')->exists($backupPath)) {
            throw new \RuntimeException("Backup no encontrado: {$backupPath}");
        }

        $config = config('backups');
        $connection = config("database.connections.{$config['database']['connection']}");

        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];
        $host = $connection['host'];
        $port = $connection['port'] ?? 5432;

        // Si es remoto, descargarlo temporalmente
        $isRemote = Storage::disk('s3')->exists($backupPath);
        $tempPath = null;

        if ($isRemote) {
            $tempPath = storage_path('app/temp/'.basename($backupPath));
            $tempDir = dirname($tempPath);
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $disk = Storage::disk(config('backups.remote.disk'));
            file_put_contents($tempPath, $disk->get($backupPath));
            $backupPath = $tempPath;
        }

        // Descomprimir si es necesario
        $isCompressed = str_ends_with($backupPath, '.gz');
        $finalPath = $backupPath;

        if ($isCompressed) {
            $uncompressedPath = str_replace('.gz', '', $backupPath);
            exec("gunzip -c {$backupPath} > {$uncompressedPath}", $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('Error al descomprimir backup: '.implode("\n", $output));
            }

            $finalPath = $uncompressedPath;
        }

        try {
            // Determinar si es formato custom (-F c) o SQL plano
            $isCustomFormat = str_ends_with($finalPath, '.sql') === false ||
                              (file_exists($finalPath) && ! is_readable($finalPath));

            // Verificar si el archivo es formato custom (binario) o SQL (texto)
            if (file_exists($finalPath)) {
                $fileContent = file_get_contents($finalPath, false, null, 0, 4);
                $isCustomFormat = $fileContent !== false &&
                                 (strpos($fileContent, 'PGDMP') === 0 ||
                                  strpos($fileContent, "\x1f\x8b") === 0); // Gzip header
            }

            if ($isCustomFormat) {
                // Restaurar backup formato custom
                $command = sprintf(
                    'PGPASSWORD=%s pg_restore -h %s -p %s -U %s -d %s --clean --if-exists %s',
                    escapeshellarg($password),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($database),
                    escapeshellarg($finalPath)
                );
            } else {
                // Restaurar backup SQL plano
                $command = sprintf(
                    'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s',
                    escapeshellarg($password),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($database),
                    escapeshellarg($finalPath)
                );
            }

            exec($command.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('Error al restaurar backup: '.implode("\n", $output));
            }

            Log::info('Backup restaurado exitosamente', [
                'backup' => basename($backupPath),
            ]);

            return true;
        } finally {
            // Limpiar archivos temporales
            if ($isCompressed && file_exists($finalPath) && $finalPath !== $backupPath) {
                unlink($finalPath);
            }
            if ($tempPath && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Limpiar backups antiguos según política de retención
     *
     * @return array Estadísticas de limpieza
     */
    public static function cleanOldBackups(): array
    {
        $config = config('backups');
        $stats = [
            'deleted_local' => 0,
            'deleted_remote' => 0,
            'kept_daily' => 0,
            'kept_weekly' => 0,
            'kept_monthly' => 0,
        ];

        $backups = self::listBackups(null, true);
        $now = now();

        // Separar backups por tipo (diario, semanal, mensual)
        $dailyBackups = [];
        $weeklyBackups = [];
        $monthlyBackups = [];

        foreach ($backups as $backup) {
            $createdAt = Carbon::parse($backup['created_at']);
            $daysOld = $now->diffInDays($createdAt);

            // Determinar tipo según antigüedad
            if ($daysOld <= 7) {
                $dailyBackups[] = $backup;
            } elseif ($daysOld <= 30) {
                // Solo mantener uno por semana
                $weekKey = $createdAt->format('Y-W');
                if (! isset($weeklyBackups[$weekKey])) {
                    $weeklyBackups[$weekKey] = $backup;
                } else {
                    // Mantener el más reciente de la semana
                    if ($createdAt->gt(Carbon::parse($weeklyBackups[$weekKey]['created_at']))) {
                        $weeklyBackups[$weekKey] = $backup;
                    }
                }
            } else {
                // Solo mantener uno por mes
                $monthKey = $createdAt->format('Y-m');
                if (! isset($monthlyBackups[$monthKey])) {
                    $monthlyBackups[$monthKey] = $backup;
                } else {
                    // Mantener el más reciente del mes
                    if ($createdAt->gt(Carbon::parse($monthlyBackups[$monthKey]['created_at']))) {
                        $monthlyBackups[$monthKey] = $backup;
                    }
                }
            }
        }

        // Backups a mantener
        $backupsToKeep = array_merge(
            $dailyBackups,
            array_values($weeklyBackups),
            array_values($monthlyBackups)
        );
        $backupsToKeepFilenames = array_column($backupsToKeep, 'filename');

        // Eliminar backups que no están en la lista de mantener
        foreach ($backups as $backup) {
            if (! in_array($backup['filename'], $backupsToKeepFilenames)) {
                try {
                    if ($backup['location'] === 'local') {
                        if (file_exists($backup['path'])) {
                            unlink($backup['path']);
                            $stats['deleted_local']++;
                        }
                    } elseif ($backup['location'] === 'remote' && $config['remote']['enabled']) {
                        $disk = Storage::disk($config['remote']['disk']);
                        if ($disk->exists($backup['path'])) {
                            $disk->delete($backup['path']);
                            $stats['deleted_remote']++;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al eliminar backup antiguo', [
                        'backup' => $backup['filename'],
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Contar backups mantenidos
                $createdAt = Carbon::parse($backup['created_at']);
                $daysOld = $now->diffInDays($createdAt);

                if ($daysOld <= 7) {
                    $stats['kept_daily']++;
                } elseif ($daysOld <= 30) {
                    $stats['kept_weekly']++;
                } else {
                    $stats['kept_monthly']++;
                }
            }
        }

        Log::info('Limpieza de backups antiguos completada', $stats);

        return $stats;
    }
}
