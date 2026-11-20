<?php

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleRemover;
use App\Support\Modules\ModuleScaffolder;

/*
 * `module:new` y `module:remove` son inversos: lo que crea el esqueleto, la baja lo deshace del todo.
 */

function proyectoConAnclas(): string
{
    $root = sys_get_temp_dir().'/apygg-new-'.bin2hex(random_bytes(4));

    foreach (['app/Providers', 'routes', 'database/seeders', 'tests/Arch', 'modules', 'database/migrations'] as $dir) {
        mkdir($root.'/'.$dir, 0777, true);
    }

    file_put_contents($root.'/app/Providers/ContractServiceProvider.php', "<?php\nuse Illuminate\\Support\\ServiceProvider;\nclass P {\n    function r() {\n        \$this->app->bind(A::class, B::class);\n\n        // Estado POR PETICIÓN/JOB (tenant activo)\n    }\n}\n");
    file_put_contents($root.'/routes/api.php', "<?php\nrequire __DIR__.'/api/a.php';\n// ─────────────────────────────────────────────────────────────────────────────────\n");
    file_put_contents($root.'/database/seeders/PermissionSeeder.php', "<?php\nreturn [\n            ['name' => 'a.read'],\n\n            // Sistema\n];\n");
    file_put_contents($root.'/tests/Arch/ArchTest.php', "<?php\nfunction x() {\n    return [\n        'App\\Services\\AuthService' => 'App\\Contracts\\AuthServiceInterface',\n    ];\n}\n");

    return $root;
}

function borrarProyecto(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

test('el esqueleto crea los archivos de la checklist y marca lo que toca en archivos compartidos', function () {
    $root = proyectoConAnclas();

    (new ModuleScaffolder($root))->create('mi-modulo');

    foreach (['app/Contracts/MiModuloServiceInterface.php', 'app/Services/MiModuloService.php', 'app/Http/Controllers/MiModulo/MiModuloController.php', 'app/Http/Requests/MiModulo/StoreMiModuloRequest.php', 'routes/api/mi-modulo.php', 'tests/Feature/Modulos/MiModuloTest.php', 'docs/mi-modulo.md', 'modules/mi-modulo.php'] as $file) {
        expect(is_file($root.'/'.$file))->toBeTrue($file);
    }

    expect((string) file_get_contents($root.'/app/Providers/ContractServiceProvider.php'))->toContain('MiModuloServiceInterface::class, MiModuloService::class); // module:mi-modulo')
        ->and((string) file_get_contents($root.'/routes/api.php'))->toContain("require __DIR__.'/api/mi-modulo.php'; // module:mi-modulo")
        ->and((string) file_get_contents($root.'/database/seeders/PermissionSeeder.php'))->toContain("'mi-modulo.read'")
        ->and((string) file_get_contents($root.'/tests/Arch/ArchTest.php'))->toContain('MiModuloService');

    borrarProyecto($root);
});

test('quitar el módulo recién creado deja los archivos compartidos como estaban', function () {
    $root = proyectoConAnclas();
    $antes = [];

    foreach (['app/Providers/ContractServiceProvider.php', 'routes/api.php', 'database/seeders/PermissionSeeder.php', 'tests/Arch/ArchTest.php'] as $file) {
        $antes[$file] = (string) file_get_contents($root.'/'.$file);
    }

    (new ModuleScaffolder($root))->create('mi-modulo');
    $remover = new ModuleRemover(new ModuleRegistry($root));
    $remover->apply($remover->plan('mi-modulo'));

    foreach ($antes as $file => $contenido) {
        expect(trim((string) file_get_contents($root.'/'.$file)))->toBe(trim($contenido), $file);
    }

    expect(is_file($root.'/app/Services/MiModuloService.php'))->toBeFalse()
        ->and((new ModuleRegistry($root))->isPresent((new ModuleRegistry($root))->find('mi-modulo') ?? throw new RuntimeException('sin manifiesto')))->toBeFalse();

    borrarProyecto($root);
});

test('el esqueleto rechaza nombres inválidos y módulos que ya existen sin tocar nada', function () {
    $root = proyectoConAnclas();
    $scaffolder = new ModuleScaffolder($root);

    expect(fn () => $scaffolder->create('Mi_Modulo'))->toThrow(RuntimeException::class);

    $scaffolder->create('uno');

    expect(fn () => $scaffolder->create('uno'))->toThrow(RuntimeException::class, 'ya existe');

    borrarProyecto($root);
});
