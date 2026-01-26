<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--compress : Comprimir el backup}';

    protected $description = 'Crear backup de la base de datos';

    public function handle(): int
    {
        $this->info('Creando backup de la base de datos...');

        try {
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');
            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "backup_{$database}_{$timestamp}.sql";
            $backupPath = storage_path("app/backups/{$filename}");

            // Crear directorio si no existe
            if (! is_dir(dirname($backupPath))) {
                mkdir(dirname($backupPath), 0755, true);
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
                throw new \RuntimeException('Error al ejecutar pg_dump');
            }

            // Comprimir si se solicita
            if ($this->option('compress')) {
                $compressedPath = $backupPath.'.gz';
                exec("gzip -c {$backupPath} > {$compressedPath}");
                unlink($backupPath);
                $backupPath = $compressedPath;
                $filename .= '.gz';
            }

            $fileSize = filesize($backupPath);
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);

            $this->info("Backup creado exitosamente: {$filename} ({$fileSizeMB} MB)");

            Log::info('Backup de base de datos creado', [
                'filename' => $filename,
                'size_mb' => $fileSizeMB,
                'path' => $backupPath,
            ]);

            // Aquí puedes agregar lógica para:
            // - Subir el backup a S3 u otro almacenamiento
            // - Eliminar backups antiguos (mantener solo los últimos N)
            // - Enviar notificación de éxito

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al crear backup: '.$e->getMessage());
            Log::error('Error al crear backup de base de datos', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
