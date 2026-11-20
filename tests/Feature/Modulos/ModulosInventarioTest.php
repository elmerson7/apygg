<?php

use App\Support\Modules\ModuleRegistry;

/*
 * Inventario de módulos (docs/modules.md): cada archivo del proyecto pertenece a un módulo (núcleo u opcional),
 * los opcionales no se pisan entre sí, las dependencias son coherentes y los marcadores `module:<nombre>` de
 * los archivos compartidos apuntan a módulos que existen y vienen balanceados.
 */

/** @return list<string> */
function archivosDelProyecto(): array
{
    $files = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'lang', 'resources', 'tests', 'modules', 'make', 'stubs', 'docker', 'scripts'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
            }
        }
    }

    sort($files);

    return $files;
}

function reclama(string $claim, string $file): bool
{
    return $file === $claim
        || str_starts_with($file, rtrim($claim, '/').'/')
        || (str_contains($claim, '*') && fnmatch($claim, $file));
}

test('cada archivo del proyecto pertenece a algún módulo (núcleo u opcional)', function () {
    $modules = (new ModuleRegistry(base_path()))->all();
    $sinDueno = [];

    foreach (archivosDelProyecto() as $file) {
        $owned = false;

        foreach ($modules as $module) {
            foreach ($module->claims() as $claim) {
                if (reclama($claim, $file)) {
                    $owned = true;
                    break 2;
                }
            }
        }

        if (! $owned && ! str_ends_with($file, '.gitignore') && ! str_ends_with($file, '.gitkeep')) {
            $sinDueno[] = $file;
        }
    }

    expect($sinDueno)->toBe([], 'Archivos sin módulo: añádelos a modules/<modulo>.php (docs/modules.md): '.implode(', ', $sinDueno));
});

test('ningún archivo lo reclaman dos módulos opcionales a la vez (manda el reclamo más específico)', function () {
    $optional = array_filter((new ModuleRegistry(base_path()))->all(), fn ($m) => ! $m->core);
    $duplicados = [];

    foreach (archivosDelProyecto() as $file) {
        $matches = [];

        foreach ($optional as $module) {
            foreach ($module->claims() as $claim) {
                if (reclama($claim, $file)) {
                    $matches[] = ['module' => $module->name, 'claim' => $claim];
                }
            }
        }

        // Un reclamo que contiene a otro (carpeta `app/Filament` frente al archivo concreto de `privacy`) cede ante el más específico.
        $matches = array_values(array_filter($matches, function (array $a) use ($matches): bool {
            foreach ($matches as $b) {
                if ($b['module'] !== $a['module'] && strlen($b['claim']) > strlen($a['claim']) && str_starts_with($b['claim'], rtrim($a['claim'], '/').'/')) {
                    return false;
                }
            }

            return true;
        }));

        $duenos = array_values(array_unique(array_column($matches, 'module')));

        if (count($duenos) > 1) {
            $duplicados[] = $file.' => '.implode(', ', $duenos);
        }
    }

    expect($duplicados)->toBe([]);
});

test('las dependencias existen, no hay ciclos y el núcleo no depende de un opcional', function () {
    $modules = (new ModuleRegistry(base_path()))->all();

    foreach ($modules as $module) {
        foreach ($module->depends as $dep) {
            expect($modules)->toHaveKey($dep);

            if ($module->core) {
                expect($modules[$dep]->core)->toBeTrue("El núcleo «{$module->name}» no puede depender del opcional «{$dep}»");
            }

            expect($modules[$dep]->depends)->not->toContain($module->name);
        }
    }
});

test('los marcadores de los archivos compartidos pertenecen a módulos opcionales y vienen balanceados', function () {
    $modules = (new ModuleRegistry(base_path()))->all();
    $problemas = [];

    foreach (archivosDelProyecto() as $file) {
        if (! preg_match('/\.(php|xml|ya?ml|mk|md|example)$/', $file) || str_starts_with($file, 'app/Support/Modules/') || str_starts_with($file, 'tests/Unit/Modules/') || str_starts_with($file, 'tests/Feature/Modulos/ModulosInventario')) {
            continue;
        }

        $text = (string) file_get_contents(base_path($file));

        if (! str_contains($text, 'module:')) {
            continue;
        }

        preg_match_all('~(?://|#|<!--|\{\{--|/\*|\*)\s*module:([a-z0-9-]+)(:start|:end)?~', $text, $m, PREG_SET_ORDER);
        $abiertos = [];

        foreach ($m as $hit) {
            $name = $hit[1];

            if (! isset($modules[$name]) || $modules[$name]->core) {
                $problemas[] = "{$file}: marcador de un módulo que no existe o es núcleo ({$name})";

                continue;
            }

            $kind = $hit[2] ?? '';
            $abiertos[$name] = ($abiertos[$name] ?? 0) + ($kind === ':start' ? 1 : ($kind === ':end' ? -1 : 0));
        }

        foreach ($abiertos as $name => $balance) {
            if ($balance !== 0) {
                $problemas[] = "{$file}: bloque module:{$name} sin cerrar";
            }
        }
    }

    expect($problemas)->toBe([]);
});

test('las tablas de un módulo no las comparte otro y el listado distingue presente de quitado', function () {
    $registry = new ModuleRegistry(base_path());
    $tablas = [];

    foreach ($registry->all() as $module) {
        foreach ($module->tables as $table) {
            expect($tablas)->not->toHaveKey($table);
            $tablas[$table] = $module->name;
        }
    }

    $this->artisan('module:list')->assertSuccessful();
});
