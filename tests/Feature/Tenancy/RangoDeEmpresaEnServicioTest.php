<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * Con equipos de Spatie apagados los roles `tenant-*` son GLOBALES: el `permission:tenant-x,api` de una ruta lo pasa
 * quien tenga ese rol en OTRA empresa. Toda ruta de empresa debe volver a comprobar en el servicio que el rol del
 * actor en ESTE tenant concede el permiso (`TenantRoles::actorRankFor`). Ver docs/tenant-onboarding.md.
 */

// Permisos que cualquier miembro tiene (`tenant-member`): ser dueño en otra empresa no da nada que un miembro no tenga.
const PERMISOS_DE_LECTURA_DE_MIEMBRO = ['tenant-members.read', 'tenant-settings.read'];

/** @return list<string> */
function permisosDeEmpresaEnRutas(): array
{
    $permisos = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $lista = explode(',', substr($middleware, strlen('permission:')))[0];
                foreach (explode('|', $lista) as $permiso) {
                    if (str_starts_with($permiso, 'tenant-')) {
                        $permisos[$permiso] = true;
                    }
                }
            }
        }
    }

    return array_keys($permisos);
}

test('cada permiso de empresa de una ruta se vuelve a comprobar con actorRankFor en el servicio', function () {
    $fuentes = collect(File::allFiles(app_path()))->map(fn ($f) => $f->getContents());
    $conRango = $fuentes->filter(fn (string $c) => str_contains($c, 'actorRankFor('));
    // Los exportadores e importadores declaran su permiso; el servicio lo recheca con actorRankFor(..., ->permission()).
    $declarados = $fuentes->filter(fn (string $c) => preg_match('/function permission\(\): string/', $c) === 1);
    $exportService = app_path('Services/ExportService.php'); // el módulo export es quitable
    $exportRecheca = File::exists($exportService) && str_contains(File::get($exportService), 'actorRankFor($this->tenant->require(), $user, $exporter->permission())');

    $sinRecheque = [];

    foreach (permisosDeEmpresaEnRutas() as $permiso) {
        if (in_array($permiso, PERMISOS_DE_LECTURA_DE_MIEMBRO, true)) {
            continue;
        }

        $literal = "'{$permiso}'";
        $cubierto = $conRango->contains(fn (string $c) => str_contains($c, $literal))
            || ($exportRecheca && $declarados->contains(fn (string $c) => str_contains($c, $literal)));

        if (! $cubierto) {
            $sinRecheque[] = $permiso;
        }
    }

    expect(permisosDeEmpresaEnRutas())->not->toBeEmpty()
        ->and($sinRecheque)->toBe([], 'Rutas de empresa sin TenantRoles::actorRankFor en el servicio: '.implode(', ', $sinRecheque));
});
