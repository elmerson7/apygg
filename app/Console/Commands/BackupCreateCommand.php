<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\LogService;
use Illuminate\Console\Command;

/**
 * BackupCreateCommand
 *
 * Comando para crear backups de base de datos y archivos.
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
                            {--files : Crear backup de archivos}
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
    public function handle(): int
    {
        $this->info('Iniciando creación de backups...');

        $compress = ! $this->option('no-compress');
        $uploadToRemote = ! $this->option('no-upload');
        $backupDatabase = $this->option('database') || (! $this->option('files'));
        $backupFiles = $this->option('files');

        $createdBackups = [];

        try {
            // Backup de base de datos
            if ($backupDatabase) {
                $this->info('Creando backup de base de datos...');
                $backupInfo = BackupService::createDatabaseBackup($compress, $uploadToRemote);

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

            // Backup de archivos (si está habilitado)
            if ($backupFiles) {
                $this->warn('Backup de archivos aún no implementado');
                // TODO: Implementar backup de archivos
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
