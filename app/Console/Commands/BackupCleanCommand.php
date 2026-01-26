<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\LogService;
use Illuminate\Console\Command;

/**
 * BackupCleanCommand
 *
 * Comando para limpiar backups antiguos según política de retención.
 */
class BackupCleanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:clean {--dry-run : Simular limpieza sin eliminar archivos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpiar backups antiguos según política de retención';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Modo simulación (dry-run): No se eliminarán archivos');
            $this->line('');
        }

        $this->info('Limpiando backups antiguos...');

        try {
            $stats = BackupService::cleanOldBackups();

            $this->line('');
            $this->info('Limpieza completada:');
            $this->line("  • Eliminados locales: {$stats['deleted_local']}");
            $this->line("  • Eliminados remotos: {$stats['deleted_remote']}");
            $this->line("  • Mantenidos diarios: {$stats['kept_daily']}");
            $this->line("  • Mantenidos semanales: {$stats['kept_weekly']}");
            $this->line("  • Mantenidos mensuales: {$stats['kept_monthly']}");

            LogService::info('Limpieza de backups completada', $stats);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al limpiar backups: '.$e->getMessage());
            LogService::error('Error al limpiar backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
