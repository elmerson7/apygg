<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * BackupListCommand
 *
 * Comando para listar backups disponibles.
 */
class BackupListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:list 
                            {--type= : Filtrar por tipo (database, files)}
                            {--remote : Incluir backups remotos}
                            {--format=table : Formato de salida (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listar backups disponibles';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $includeRemote = $this->option('remote');
        $format = $this->option('format');

        try {
            $backups = BackupService::listBackups($type, $includeRemote);

            if (empty($backups)) {
                $this->info('No se encontraron backups.');
                $this->line('');
                $this->line('Crea un backup con: php artisan backup:create');

                return Command::SUCCESS;
            }

            if ($format === 'json') {
                $this->line(json_encode($backups, JSON_PRETTY_PRINT));

                return Command::SUCCESS;
            }

            // Formato tabla
            $this->info('Backups disponibles:');
            $this->line('');

            $tableData = [];
            foreach ($backups as $backup) {
                $tableData[] = [
                    $backup['filename'],
                    $backup['size_mb'].' MB',
                    $backup['type'],
                    $backup['location'],
                    $backup['compressed'] ? 'Sí' : 'No',
                    $backup['created_at_human'],
                ];
            }

            $this->table(
                ['Archivo', 'Tamaño', 'Tipo', 'Ubicación', 'Comprimido', 'Creado'],
                $tableData
            );

            $this->line('');
            $this->line('Total: '.count($backups).' backup(s)');

            // Mostrar estadísticas de retención
            $this->line('');
            $this->info('Política de retención:');
            $config = config('backups');
            $this->line("  • Diarios: {$config['retention']['daily']} días");
            $this->line("  • Semanales: {$config['retention']['weekly']} días");
            $this->line("  • Mensuales: {$config['retention']['monthly']} días");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al listar backups: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
