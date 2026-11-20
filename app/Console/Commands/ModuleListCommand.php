<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Lista los módulos con su estado (presente/quitado), tipo y dependencias. Uso: php artisan module:list
 */
class ModuleListCommand extends Command
{
    protected $signature = 'module:list {--path= : Raíz del proyecto (por defecto, la actual)}';

    protected $description = 'Listar los módulos (núcleo y opcionales), su estado y sus dependencias';

    public function handle(): int
    {
        $registry = new ModuleRegistry((string) ($this->option('path') ?: base_path()));
        $rows = [];

        foreach ($registry->all() as $module) {
            $rows[] = [
                $module->name,
                $module->core ? 'núcleo' : 'opcional',
                $registry->isPresent($module) ? 'presente' : 'quitado',
                implode(', ', $module->depends) ?: '-',
                implode(', ', $registry->dependents($module->name)) ?: '-',
                $module->description,
            ];
        }

        $this->table(['Módulo', 'Tipo', 'Estado', 'Depende de', 'Lo usan', 'Descripción'], $rows);

        return self::SUCCESS;
    }
}
