<?php

namespace App\Console\Commands;

use App\Contracts\BackupServiceInterface;
use App\Services\LogService;
use Illuminate\Console\Command;

/**
 * BackupCreateCommand
 *
 * Comando para crear backups de base de datos. El de archivos no existe: `--files` falla con error.
 */
class BackupCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:create 
                            {--database : Crear backup de base de datos (por defecto)}
                            {--files : NO soportado: falla con error (los archivos no se respaldan con este comando)}
                            {--no-compress : No comprimir el backup}
                            {--no-upload : No subir a almacenamiento remoto}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crear backup de base de datos y/o archivos';

    /**
     * Execute the console command.
     */
    public function handle(BackupServiceInterface $backups): int
    {
        if ($this->option('files')) {
            // No se acepta en silencio: el respaldo de archivos no lo hace este comando (ver docs/backups-system.md).
            $this->error('--files no está soportado: este comando solo respalda la base de datos. Los archivos viven en el disco configurado (FILE_DISK_*): respáldalos con el versionado/replicación del bucket S3 o con un snapshot del volumen.');

            return Command::FAILURE;
        }

        $this->info('Iniciando creación de backups...');

        $compress = ! $this->option('no-compress');
        $uploadToRemote = ! $this->option('no-upload');
        $backupDatabase = $this->option('database') || (! $this->option('files'));

        $createdBackups = [];

        try {
            // Backup de base de datos
            if ($backupDatabase) {
                $this->info('Creando backup de base de datos...');
                $backupInfo = $backups->createDatabaseBackup($compress, $uploadToRemote);

                $this->line("  ✓ Backup creado: {$backupInfo['filename']}");
                $this->line("  ✓ Tamaño: {$backupInfo['size_mb']} MB");
                $this->line('  ✓ Comprimido: '.($backupInfo['compressed'] ? 'Sí' : 'No'));
                $this->line("  ✓ Ubicación local: {$backupInfo['path']}");

                if ($backupInfo['remote_uploaded'] ?? false) {
                    $this->line("  ✓ Subido a S3: {$backupInfo['remote_path']}");
                } elseif (isset($backupInfo['remote_upload_error'])) {
                    $this->warn("  ⚠ No se pudo subir a S3: {$backupInfo['remote_upload_error']}");
                    $this->line('  ℹ Backup guardado en storage local como respaldo');
                } elseif (! config('backups.remote.enabled')) {
                    $this->line('  ℹ Almacenamiento remoto deshabilitado (solo local)');
                }

                $createdBackups[] = $backupInfo;
            }

            $this->info('✓ Backups creados exitosamente');

            LogService::info('Backups creados manualmente', [
                'backups' => $createdBackups,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al crear backups: '.$e->getMessage());
            LogService::error('Error al crear backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
