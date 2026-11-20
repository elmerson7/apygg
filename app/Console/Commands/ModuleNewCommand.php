<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleScaffolder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Crea el esqueleto de un módulo opcional nuevo. Uso: php artisan module:new <nombre-en-kebab> [--dry-run]
 */
class ModuleNewCommand extends Command
{
    protected $signature = 'module:new {name : Nombre en kebab-case (p. ej. mi-modulo)}
        {--dry-run : Mostrar los archivos que crearía/editaría}
        {--path= : Raíz del proyecto (por defecto, la actual)}';

    protected $description = 'Crear el esqueleto de un módulo opcional (servicio+interfaz+binding, controller, request, ruta, permiso, test, doc)';

    public function handle(): int
    {
        $scaffolder = new ModuleScaffolder(rtrim((string) ($this->option('path') ?: base_path()), '/'));

        try {
            $files = $scaffolder->create((string) $this->argument('name'), (bool) $this->option('dry-run'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $this->line(($this->option('dry-run') ? 'tocaría ' : 'listo ').$file);
        }

        if (! $this->option('dry-run')) {
            $this->info('Siguiente: make migrate/seed si añades tablas, `make openapi`, `make check` y completa la doc.');
        }

        return self::SUCCESS;
    }
}
