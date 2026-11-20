<?php

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleRemover;

/*
 * Baja de un módulo sobre un proyecto de juguete en un directorio temporal (nunca el proyecto real).
 */

function moduloDeJuguete(): string
{
    $root = sys_get_temp_dir().'/apygg-mod-'.bin2hex(random_bytes(4));
    mkdir($root.'/modules', 0777, true);
    mkdir($root.'/app/Services/Extra', 0777, true);
    mkdir($root.'/app/Providers', 0777, true);
    mkdir($root.'/database/migrations', 0777, true);

    file_put_contents($root.'/modules/extra.php', "<?php return ['description' => 'x', 'core' => false, 'depends' => [], 'files' => ['app/Services/Extra', 'app/Foo.php'], 'tables' => ['extra_things']];");
    file_put_contents($root.'/modules/usa-extra.php', "<?php return ['description' => 'y', 'core' => false, 'depends' => ['extra'], 'files' => ['app/Bar.php']];");
    file_put_contents($root.'/modules/base.php', "<?php return ['description' => 'z', 'core' => true, 'files' => []];");
    file_put_contents($root.'/app/Services/Extra/Servicio.php', "<?php\nnamespace App\\Services\\Extra;\nclass Servicio {}\n");
    file_put_contents($root.'/app/Foo.php', "<?php\nnamespace App;\nclass Foo {}\n");
    file_put_contents($root.'/app/Bar.php', "<?php\nnamespace App;\nclass Bar {}\n");
    file_put_contents($root.'/app/Providers/P.php', "<?php\nuse App\\Foo;\nuse App\\Otro;\n\$a = 1;\n\$b = Foo::class; // module:extra\n// module:extra:start\n\$c = 3;\n// module:extra:end\n");

    return $root;
}

function borrarArbol(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

test('el plan de baja lista archivos, líneas marcadas, imports y la migración de tablas', function () {
    $root = moduloDeJuguete();
    $remover = new ModuleRemover(new ModuleRegistry($root));

    $plan = $remover->plan('extra');

    expect($plan['present'])->toBeTrue()
        ->and($plan['delete'])->toContain('app/Foo.php', 'app/Services/Extra/Servicio.php')
        ->and(array_keys($plan['edits']))->toBe(['app/Providers/P.php'])
        ->and($plan['edits']['app/Providers/P.php']['removed'])->toBe(4)
        ->and(array_keys($plan['imports']))->toBe(['app/Providers/P.php'])
        ->and($plan['migration'])->toContain('_drop_extra_module_tables.php')
        ->and($plan['blockers'])->toBe(['usa-extra']);

    borrarArbol($root);
});

test('aplicar la baja borra archivos, limpia marcadores e imports y crea la migración; repetirla no hace nada', function () {
    $root = moduloDeJuguete();
    $registry = new ModuleRegistry($root);
    $remover = new ModuleRemover($registry);

    // Primero se quita quien depende (el comando lo exige); aquí se salta el comando y se borra a mano.
    unlink($root.'/app/Bar.php');

    $remover->apply($remover->plan('extra'));

    expect(is_file($root.'/app/Foo.php'))->toBeFalse()
        ->and(is_dir($root.'/app/Services/Extra'))->toBeFalse()
        ->and(trim((string) file_get_contents($root.'/app/Providers/P.php')))->toBe("<?php\nuse App\\Otro;\n\$a = 1;")
        ->and(glob($root.'/database/migrations/*_drop_extra_module_tables.php'))->toHaveCount(1)
        ->and($registry->isPresent($registry->find('extra')))->toBeFalse();

    $again = $remover->plan('extra');

    expect($again['present'])->toBeFalse()
        ->and($again['edits'])->toBe([])
        ->and($again['migration'])->toBeNull();

    borrarArbol($root);
});

test('la baja limpia el include marcado del Makefile raíz para que make siga funcionando', function () {
    $root = moduloDeJuguete();
    file_put_contents($root.'/Makefile', "include make/app.mk\ninclude make/extra.mk # module:extra\ninclude make/help.mk\n");
    $remover = new ModuleRemover(new ModuleRegistry($root));

    $plan = $remover->plan('extra');

    expect(array_keys($plan['edits']))->toContain('Makefile')
        ->and($plan['edits']['Makefile']['removed'])->toBe(1);

    unlink($root.'/app/Bar.php');
    $remover->apply($plan);

    expect((string) file_get_contents($root.'/Makefile'))->toBe("include make/app.mk\ninclude make/help.mk\n");

    borrarArbol($root);
});

test('un módulo de núcleo o inexistente no se puede quitar', function () {
    $root = moduloDeJuguete();
    $remover = new ModuleRemover(new ModuleRegistry($root));

    expect(fn () => $remover->plan('base'))->toThrow(RuntimeException::class, 'núcleo')
        ->and(fn () => $remover->plan('nada'))->toThrow(RuntimeException::class);

    borrarArbol($root);
});
