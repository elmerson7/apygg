<?php

namespace App\Console\Commands;

use App\Support\Database\MigrationLinter;
use Illuminate\Console\Command;

/**
 * Revisa las migraciones posteriores a la base permitida (`config/migration-lint.php`) y falla si
 * alguna usa un patrón peligroso para desplegar sin parada. Docs: docs/migrations.md §9.
 *
 * Uso: php artisan migrations:lint [--path=database/migrations] [--all]
 */
class MigrationsLintCommand extends Command
{
    protected $signature = 'migrations:lint
        {--path= : Directorio de migraciones (por defecto database/migrations)}
        {--all : Revisar también las anteriores a la base permitida}';

    protected $description = 'Detectar patrones de migración peligrosos para desplegar sin parada';

    public function handle(): int
    {
        $dir = (string) ($this->option('path') ?: database_path('migrations'));
        $baseline = (string) config('migration-lint.baseline', '');
        /** @var array<string, list<string>> $exceptions */
        $exceptions = (array) config('migration-lint.exceptions', []);
        /** @var list<string> $large */
        $large = (array) config('migration-lint.large_tables', []);
        $linter = new MigrationLinter;

        $files = glob(rtrim($dir, '/').'/*.php') ?: [];
        sort($files);

        $checked = 0;
        $errors = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (! $this->option('all') && $baseline !== '' && strcmp($name, $baseline) <= 0) {
                continue;
            }
            $checked++;
            $skip = $exceptions[$name] ?? [];
            if (in_array('*', $skip, true)) {
                continue;
            }
            foreach ($linter->lint((string) file_get_contents($file), $large) as $v) {
                if (in_array($v['rule'], $skip, true)) {
                    continue;
                }
                $errors++;
                $this->error("{$name}.php:{$v['line']} [{$v['rule']}] {$v['message']}");
            }
        }

        if ($errors > 0) {
            $this->line('Corrige la migración o márcala con `// migration-lint: ignore <regla> -- motivo` (docs/migrations.md §9).');

            return self::FAILURE;
        }

        $this->info("OK: {$checked} migraciones revisadas, sin patrones peligrosos.");

        return self::SUCCESS;
    }
}
