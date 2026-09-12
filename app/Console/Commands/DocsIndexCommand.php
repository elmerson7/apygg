<?php

namespace App\Console\Commands;

use App\Services\Docs\DocsIndexer;
use Illuminate\Console\Command;

/**
 * DocsIndexCommand
 *
 * Indexa la documentación del proyecto para que se pueda buscar y consultar.
 *
 * Solo reindexa lo que ha cambiado (compara el hash del contenido), así que se
 * puede ejecutar en cada despliegue sin coste apreciable.
 *
 * Uso:
 *   php artisan docs:index
 *   php artisan docs:index --force
 */
class DocsIndexCommand extends Command
{
    protected $signature = 'docs:index
                            {--force : Reindexar aunque el contenido no haya cambiado}';

    protected $description = 'Indexar la documentación del proyecto para el asistente';

    public function handle(DocsIndexer $indexer): int
    {
        $sources = (array) config('docs-assistant.sources', []);

        if ($sources === []) {
            $this->error('No hay fuentes configuradas en config/docs-assistant.php.');

            return Command::FAILURE;
        }

        $this->info('Indexando documentación...');

        foreach ($sources as $scope => $paths) {
            $this->line("  {$scope}: ".implode(', ', (array) $paths));
        }

        $this->newLine();

        $stats = $indexer->reindex((bool) $this->option('force'));

        $this->table(['Métrica', 'Valor'], [
            ['Ficheros procesados', $stats['files']],
            ['Fragmentos indexados', $stats['chunks']],
            ['Sin cambios (omitidos)', $stats['skipped']],
            ['Fragmentos eliminados', $stats['removed']],
        ]);

        $this->info('✓ Indexación completada.');

        if ($stats['chunks'] === 0 && $stats['skipped'] === 0) {
            $this->warn('No se indexó ningún fragmento. Revisa las rutas de config/docs-assistant.php.');
        }

        return Command::SUCCESS;
    }
}
