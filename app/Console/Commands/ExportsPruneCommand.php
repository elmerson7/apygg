<?php

namespace App\Console\Commands;

use App\Contracts\ExportServiceInterface;
use App\Contracts\ImportServiceInterface;
use Illuminate\Console\Command;

class ExportsPruneCommand extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Marca como fallidas las exportaciones/importaciones atascadas y borra las caducadas con sus archivos';

    public function handle(ExportServiceInterface $exports, ImportServiceInterface $imports): int
    {
        $this->info("Exportaciones atascadas marcadas como fallidas: {$exports->failStuck()}");
        $this->info("Importaciones atascadas marcadas como fallidas: {$imports->failStuck()}");
        $this->info("Exportaciones borradas: {$exports->prune()}");
        $this->info("Importaciones borradas: {$imports->prune()}");

        return self::SUCCESS;
    }
}
