<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\LogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * BackupRestoreRemoteCommand
 *
 * Descarga un backup desde el almacenamiento remoto (S3/R2/MinIO) y lo restaura
 * sobre la base de datos actual. Sin `--file` toma el más reciente.
 *
 * Complementa a `backup:restore`, que solo opera sobre archivos locales.
 *
 * Uso:
 *   php artisan backup:restore-remote
 *   php artisan backup:restore-remote --file=backup_2026_09_12.sql.gz --force
 */
class BackupRestoreRemoteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore-remote
                            {--file= : Archivo específico a restaurar (default: el más reciente)}
                            {--force : Restaurar sin pedir confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descargar un backup del almacenamiento remoto y restaurarlo';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $config = config('backups');

        if (! ($config['remote']['enabled'] ?? false)) {
            $this->error('Los backups remotos no están habilitados (BACKUP_REMOTE_ENABLED).');

            return Command::FAILURE;
        }

        $diskName = $config['remote']['disk'];
        $remotePath = $config['remote']['path'];
        $force = (bool) $this->option('force');
        $fileOption = $this->option('file');

        try {
            $disk = Storage::disk($diskName);

            $backups = [];
            foreach ($disk->files($remotePath) as $remoteFile) {
                $filename = basename($remoteFile);

                if (preg_match('/^backup_.*\.(sql|sql\.gz)$/', $filename)) {
                    $backups[] = [
                        'path' => $remoteFile,
                        'filename' => $filename,
                        'size_mb' => round($disk->size($remoteFile) / 1024 / 1024, 2),
                        'created_at' => $disk->lastModified($remoteFile),
                    ];
                }
            }

            if (empty($backups)) {
                $this->error("No se encontraron backups remotos en {$diskName}:{$remotePath}");

                return Command::FAILURE;
            }

            usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

            $selected = $fileOption
                ? collect($backups)->firstWhere('filename', $fileOption)
                : $backups[0];

            if (! $selected) {
                $this->error("Backup no encontrado: {$fileOption}");
                $this->line('Usa `php artisan backup:list --remote` para ver los disponibles.');

                return Command::FAILURE;
            }

            $this->table(
                ['Propiedad', 'Valor'],
                [
                    ['Archivo', $selected['filename']],
                    ['Tamaño', $selected['size_mb'].' MB'],
                    ['Fecha', date('Y-m-d H:i:s', $selected['created_at'])],
                    ['Ubicación', "remote ({$diskName})"],
                ]
            );

            if (! $force) {
                $this->warn('⚠️  Esta operación SOBRESCRIBE la base de datos actual.');

                if (! $this->confirm('¿Continuar?', false)) {
                    $this->info('Operación cancelada.');

                    return Command::SUCCESS;
                }
            }

            $tempPath = storage_path('app/temp/'.$selected['filename']);
            $tempDir = dirname($tempPath);

            if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
                $this->error("No se pudo crear el directorio temporal: {$tempDir}");

                return Command::FAILURE;
            }

            $this->info("Descargando desde {$diskName}...");
            file_put_contents($tempPath, $disk->get($selected['path']));
            $this->info('✓ Descarga completada');

            $this->info('Restaurando base de datos...');
            BackupService::restoreDatabaseBackup($tempPath, false);
            $this->info('✓ Backup restaurado exitosamente');

            @unlink($tempPath);

            LogService::info('Backup remoto restaurado', [
                'backup' => $selected['filename'],
                'disk' => $diskName,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            LogService::error('Error al restaurar backup remoto', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
