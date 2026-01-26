<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\LogService;
use Illuminate\Console\Command;

/**
 * BackupRestoreCommand
 *
 * Comando para restaurar backups de base de datos.
 */
class BackupRestoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore 
                            {backup : Nombre del archivo de backup o ruta completa}
                            {--force : Forzar restauración sin confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restaurar un backup de base de datos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $backupInput = $this->argument('backup');
        $force = $this->option('force');

        // Buscar el backup
        $backupPath = $this->findBackup($backupInput);

        if (! $backupPath) {
            $this->error("Backup no encontrado: {$backupInput}");
            $this->line('');
            $this->line('Usa "php artisan backup:list" para ver backups disponibles.');

            return Command::FAILURE;
        }

        $this->info("Backup encontrado: {$backupPath}");

        // Mostrar información del backup
        $backupInfo = $this->getBackupInfo($backupPath);
        $this->table(
            ['Propiedad', 'Valor'],
            [
                ['Archivo', $backupInfo['filename']],
                ['Tamaño', $backupInfo['size_mb'].' MB'],
                ['Fecha', $backupInfo['created_at_human']],
                ['Comprimido', $backupInfo['compressed'] ? 'Sí' : 'No'],
            ]
        );

        // Confirmación
        if (! $force) {
            $this->warn('⚠️  ADVERTENCIA: Esta operación reemplazará todos los datos actuales de la base de datos.');
            $this->warn('⚠️  Asegúrate de tener un backup reciente antes de continuar.');

            if (! $this->confirm('¿Estás seguro de que deseas restaurar este backup?', false)) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        try {
            $this->info('Restaurando backup...');
            $this->withProgressBar([1], function () use ($backupPath) {
                BackupService::restoreDatabaseBackup($backupPath, false);
            });
            $this->newLine();

            $this->info('✓ Backup restaurado exitosamente');

            LogService::info('Backup restaurado', [
                'backup' => basename($backupPath),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al restaurar backup: '.$e->getMessage());
            LogService::error('Error al restaurar backup', [
                'backup' => $backupPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Buscar el backup por nombre o ruta
     */
    protected function findBackup(string $input): ?string
    {
        // Si es una ruta absoluta y existe, usarla
        if (file_exists($input)) {
            return $input;
        }

        // Buscar en backups locales
        $localPath = config('backups.local_path');
        $localFile = "{$localPath}/{$input}";
        if (file_exists($localFile)) {
            return $localFile;
        }

        // Buscar con extensión .gz si no la tiene
        if (! str_ends_with($input, '.gz') && ! str_ends_with($input, '.sql')) {
            $localFileGz = "{$localPath}/{$input}.gz";
            if (file_exists($localFileGz)) {
                return $localFileGz;
            }
        }

        // Buscar en backups remotos
        $config = config('backups');
        if ($config['remote']['enabled']) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk($config['remote']['disk']);
                $remotePath = rtrim($config['remote']['path'], '/').'/'.$input;

                if ($disk->exists($remotePath)) {
                    return $remotePath;
                }
            } catch (\Exception $e) {
                // Ignorar errores de acceso remoto
            }
        }

        return null;
    }

    /**
     * Obtener información del backup
     */
    protected function getBackupInfo(string $backupPath): array
    {
        $filename = basename($backupPath);
        $isRemote = str_starts_with($backupPath, 's3://') || ! file_exists($backupPath);

        if ($isRemote) {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('backups.remote.disk'));
            $size = $disk->size($backupPath);
            $lastModified = $disk->lastModified($backupPath);
            $createdAt = \Carbon\Carbon::createFromTimestamp($lastModified);
        } else {
            $size = filesize($backupPath);
            $createdAt = \Carbon\Carbon::createFromTimestamp(filemtime($backupPath));
        }

        return [
            'filename' => $filename,
            'size_mb' => round($size / 1024 / 1024, 2),
            'created_at_human' => $createdAt->diffForHumans(),
            'compressed' => str_ends_with($filename, '.gz'),
        ];
    }
}
