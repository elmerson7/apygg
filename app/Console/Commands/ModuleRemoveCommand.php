<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Quita un módulo opcional con seguridad. Uso: php artisan module:remove <nombre> [--dry-run] [--no-verify]
 *
 * Comprueba dependencias, borra sus archivos, quita las líneas marcadas `module:<nombre>` de los archivos
 * compartidos, crea una migración NUEVA que elimina sus tablas y verifica que la app sigue arrancando.
 */
class ModuleRemoveCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'module:remove {name : Nombre del módulo (ver module:list)}
        {--dry-run : Mostrar lo que haría sin tocar nada}
        {--purge-migrations : Borra también las migraciones que crearon sus tablas y no crea la de baja (solo antes del primer despliegue)}
        {--no-verify : No ejecutar composer dump-autoload ni la comprobación de arranque}
        {--path= : Raíz del proyecto (por defecto, la actual; sirve para probar sobre una copia)}';

    protected $description = 'Quitar un módulo opcional (archivos, marcadores, migración de baja) y verificar que la app arranca';

    public function handle(): int
    {
        $root = rtrim(($this->stringOption('path') ?: base_path()), '/');
        $remover = new ModuleRemover(new ModuleRegistry($root));

        try {
            $plan = $remover->plan(($this->stringArgument('name') ?? ''), (bool) $this->option('purge-migrations'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $name = $plan['module']->name;

        if ($plan['blockers'] !== []) {
            $this->error("No se puede quitar «{$name}»: dependen de él los módulos presentes: ".implode(', ', $plan['blockers']).'. Quita esos primero.');

            return self::FAILURE;
        }

        if (! $plan['present'] && $plan['edits'] === []) {
            $this->info("«{$name}» ya está quitado: nada que hacer.");

            return self::SUCCESS;
        }

        $this->line(sprintf('Módulo «%s»: %d archivos a borrar, %d archivos compartidos a editar, %d con imports a limpiar.', $name, count($plan['delete']), count($plan['edits']), count($plan['imports'])));

        if ($this->option('dry-run')) {
            foreach ($plan['delete'] as $file) {
                $this->line("  borraría {$file}");
            }
            foreach ($plan['edits'] as $file => $result) {
                $this->line("  editaría {$file} (-{$result['removed']} líneas)");
            }
            foreach (array_keys($plan['imports']) as $file) {
                $this->line("  limpiaría imports en {$file}");
            }
            if ($plan['migration'] !== null) {
                $this->line("  crearía {$plan['migration']} (elimina: ".implode(', ', $plan['module']->tables).')');
            }
            $this->comment('--dry-run: no se ha cambiado nada.');

            return self::SUCCESS;
        }

        foreach ($remover->apply($plan) as $line) {
            $this->line("  {$line}");
        }

        if ($plan['packages'] !== []) {
            $this->warn('Paquetes que ya nadie usa (quítalos con composer): composer remove '.implode(' ', $plan['packages']));
        }

        if ($this->option('no-verify')) {
            return self::SUCCESS;
        }

        $this->format($root, array_keys($plan['edits'] + $plan['imports']));

        return $this->verify($root);
    }

    /**
     * Pint sobre los archivos editados (quitar líneas deja dobles saltos o imports desordenados).
     *
     * @param  list<int|string>  $files
     */
    private function format(string $root, array $files): void
    {
        $php = array_values(array_filter(array_map('strval', $files), static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root.'/'.$f)));

        if ($php !== [] && is_file($root.'/vendor/bin/pint')) {
            Process::path($root)->timeout(300)->run(['vendor/bin/pint', '-q', ...$php]);
        }
    }

    private function verify(string $root): int
    {
        foreach ([['composer', 'dump-autoload', '-q'], ['php', 'artisan', 'route:list', '--json', '-q']] as $command) {
            $result = Process::path($root)->timeout(300)->run($command);

            if (! $result->successful()) {
                $this->error('Verificación fallida ('.implode(' ', $command).'):');
                $this->line(trim($result->errorOutput().$result->output()));

                return self::FAILURE;
            }
        }

        // El contrato OpenAPI cambia al quitar rutas: se regenera (docs/api-contract.md).
        $openapi = Process::path($root)->timeout(300)->run(['php', 'artisan', 'openapi:export']);

        if (! $openapi->successful()) {
            $this->warn('No se pudo regenerar docs/openapi.json: ejecuta `make openapi`.');
        }

        $this->info('Módulo quitado y la app arranca (composer dump-autoload + route:list).');

        return self::SUCCESS;
    }
}
