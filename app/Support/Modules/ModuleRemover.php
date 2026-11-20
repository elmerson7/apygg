<?php

namespace App\Support\Modules;

use RuntimeException;

/**
 * Quita un módulo opcional: calcula el plan (qué se borra, qué líneas marcadas se quitan, qué migración se crea)
 * y, si no es simulación, lo aplica. Idempotente: un módulo ya quitado no hace nada.
 */
final class ModuleRemover
{
    /** Carpetas y archivos raíz donde se buscan marcadores `module:<nombre>`. */
    private const SCAN_DIRS = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'lang', 'resources'];

    private const SCAN_ROOT_FILES = ['.env.example', 'phpunit.xml', 'docker-compose.yml', 'AGENTS.md', 'README.md', 'TECH_STACK.md', 'CLAUDE.md'];

    private const SCAN_EXTENSIONS = ['php', 'md', 'xml', 'yml', 'yaml', 'example', 'mk'];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MarkerEditor $markers = new MarkerEditor,
    ) {}

    /**
     * @return array{module: Module, present: bool, blockers: list<string>, delete: list<string>, edits: array<string, array{content: string, removed: int}>, imports: array<string, string>, migration: ?string, packages: list<string>}
     */
    public function plan(string $name, bool $purgeMigrations = false): array
    {
        $module = $this->registry->find($name) ?? throw new RuntimeException("No existe el módulo «{$name}» (modules/{$name}.php)");

        if ($module->core) {
            throw new RuntimeException("«{$name}» es núcleo: no se quita sin rehacer el boilerplate");
        }

        if ($purgeMigrations && ! $module->purgeable) {
            throw new RuntimeException("«{$name}» no admite --purge-migrations: migraciones del núcleo tocan sus tablas. Usa la migración de baja.");
        }

        $paths = $this->registry->existingPaths($module);
        $deleteFiles = [];

        if ($purgeMigrations) {
            $paths = [...$paths, ...$this->registry->existingPaths(new Module($module->name, '', false, [], $module->migrations, [], [], [], [], [], true))];
        }

        foreach ($paths as $path) {
            foreach ($this->registry->filesUnder($path) as $file) {
                $deleteFiles[$file] = true;
            }
        }

        $edits = [];
        foreach ($this->scanFiles() as $relative) {
            if (isset($deleteFiles[$relative])) {
                continue;
            }

            $content = (string) file_get_contents($this->registry->root().'/'.$relative);

            if (! $this->markers->hasMarkers($content, $name)) {
                continue;
            }

            $result = $this->markers->strip($content, $name, $relative);

            if ($result['removed'] > 0) {
                $edits[$relative] = $result;
            }
        }

        return [
            'module' => $module,
            'present' => $paths !== [],
            'blockers' => $this->registry->presentDependents($name),
            'delete' => array_keys($deleteFiles),
            'edits' => $edits,
            'imports' => $this->importCleanups(array_keys($deleteFiles), $edits, $deleteFiles),
            'migration' => $purgeMigrations ? null : $this->dropMigrationPath($module),
            'packages' => $module->packages,
        ];
    }

    /**
     * Aplica un plan. Devuelve las líneas de informe.
     *
     * @param  array{module: Module, present: bool, blockers: list<string>, delete: list<string>, edits: array<string, array{content: string, removed: int}>, imports: array<string, string>, migration: ?string, packages: list<string>}  $plan
     * @return list<string>
     */
    public function apply(array $plan): array
    {
        $root = $this->registry->root();
        $log = [];
        $module = $plan['module'];

        foreach ($plan['edits'] as $relative => $result) {
            file_put_contents($root.'/'.$relative, $result['content']);
            $log[] = "editado {$relative} (-{$result['removed']} líneas)";
        }

        foreach ($plan['imports'] as $relative => $content) {
            file_put_contents($root.'/'.$relative, $content);
            $log[] = "imports limpiados en {$relative}";
        }

        if ($plan['migration'] !== null) {
            file_put_contents($root.'/'.$plan['migration'], $this->migrationSource($module));
            $log[] = "migración creada {$plan['migration']}";
        }

        foreach ($plan['delete'] as $path) {
            $this->delete($root.'/'.$path);
            $log[] = "borrado {$path}";
        }

        foreach (glob($root.'/bootstrap/cache/*.php') ?: [] as $cache) {
            if (basename($cache) !== '.gitignore') {
                @unlink($cache);
            }
        }

        return $log;
    }

    /**
     * @return list<string>
     */
    private function scanFiles(): array
    {
        $root = $this->registry->root();
        $found = [];

        foreach (self::SCAN_ROOT_FILES as $file) {
            if (is_file($root.'/'.$file)) {
                $found[] = $file;
            }
        }

        foreach (glob($root.'/make/*.mk') ?: [] as $mk) {
            $found[] = 'make/'.basename($mk);
        }

        foreach (self::SCAN_DIRS as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::SCAN_EXTENSIONS, true)) {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

                // El propio sistema de módulos y sus pruebas explican los marcadores con ejemplos.
                if (str_starts_with($relative, 'app/Support/Modules/') || str_starts_with($relative, 'tests/Unit/Modules/')) {
                    continue;
                }

                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Quita `use <clase borrada>;` de los PHP que se conservan.
     *
     * @param  list<string>  $deleted
     * @param  array<string, array{content: string, removed: int}>  $edits
     * @param  array<string, true>  $deleteSet
     * @return array<string, string> ruta => contenido nuevo
     */
    private function importCleanups(array $deleted, array $edits, array $deleteSet): array
    {
        $classes = [];

        foreach ($deleted as $file) {
            $fqcn = $this->classOf($file);

            if ($fqcn !== null) {
                $classes[] = $fqcn;
            }
        }

        if ($classes === []) {
            return [];
        }

        $pattern = '~^use (?:'.implode('|', array_map(static fn (string $c): string => preg_quote($c, '~'), $classes)).')(?: as \w+)?;\R~m';
        $changed = [];

        foreach ($this->scanFiles() as $relative) {
            if (! str_ends_with($relative, '.php') || isset($deleteSet[$relative])) {
                continue;
            }

            $content = $edits[$relative]['content'] ?? (string) file_get_contents($this->registry->root().'/'.$relative);
            $clean = (string) preg_replace($pattern, '', $content);

            if ($clean !== $content) {
                $changed[$relative] = $clean;
            }
        }

        return $changed;
    }

    private function classOf(string $relative): ?string
    {
        if (! str_ends_with($relative, '.php')) {
            return null;
        }

        $map = ['app/' => 'App\\', 'database/seeders/' => 'Database\\Seeders\\', 'database/factories/' => 'Database\\Factories\\'];

        foreach ($map as $dir => $namespace) {
            if (str_starts_with($relative, $dir)) {
                return $namespace.str_replace('/', '\\', substr($relative, strlen($dir), -4));
            }
        }

        return null;
    }

    private function dropMigrationPath(Module $module): ?string
    {
        if ($module->tables === []) {
            return null;
        }

        $slug = str_replace('-', '_', $module->name);

        if (glob($this->registry->root()."/database/migrations/*_drop_{$slug}_module_tables.php")) {
            return null;
        }

        return 'database/migrations/'.date('Y_m_d_His')."_drop_{$slug}_module_tables.php";
    }

    private function migrationSource(Module $module): string
    {
        $tables = implode(', ', array_map(static fn (string $t): string => "'{$t}'", $module->tables));

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baja del módulo «{$module->name}» (`php artisan module:remove {$module->name}`): elimina sus tablas.
 * Las migraciones que las crearon se conservan (ya están aplicadas en otros entornos). Irreversible:
 * `down()` no recrea nada; recupera los datos desde una copia de seguridad.
 */
// migration-lint: ignore drop-column -- baja deliberada del módulo {$module->name}
return new class extends Migration
{
    public function up(): void
    {
        foreach ([{$tables}] as \$table) {
            DB::statement('DROP TABLE IF EXISTS "'.\$table.'" CASCADE');
        }
    }

    public function down(): void
    {
        // Irreversible a propósito (ver docblock).
    }
};

PHP;
    }

    private function delete(string $absolute): void
    {
        if (is_file($absolute) || is_link($absolute)) {
            unlink($absolute);
            $this->pruneEmptyParents(dirname($absolute));

            return;
        }

        if (is_dir($absolute)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($absolute);
            $this->pruneEmptyParents(dirname($absolute));
        }
    }

    private function pruneEmptyParents(string $dir): void
    {
        $root = rtrim($this->registry->root(), '/');

        while (str_starts_with($dir, $root.'/') && is_dir($dir) && count(scandir($dir) ?: []) === 2) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
