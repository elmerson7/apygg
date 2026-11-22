<?php

test('docs/README.md enlaza todos los documentos vigentes de docs/', function () {
    $indice = file_get_contents(base_path('docs/README.md'));

    $sinEnlazar = collect(glob(base_path('docs/*.md')))
        ->map(fn (string $ruta) => basename($ruta))
        ->reject(fn (string $nombre) => $nombre === 'README.md' || str_contains($indice, "]({$nombre})"))
        ->values()->all();

    expect($sinEnlazar)->toBe([]);
});

test('los enlaces relativos de docs/README.md apuntan a archivos que existen', function () {
    preg_match_all('/\]\(([^)#]+)\)/', file_get_contents(base_path('docs/README.md')), $m);

    $rotos = collect($m[1])->reject(fn (string $ruta) => file_exists(base_path('docs/'.$ruta)))->values()->all();

    expect($rotos)->toBe([]);
});

test('cada documento de un módulo opcional va en su propia línea del índice con el marcador del módulo', function () {
    $lineas = explode("\n", file_get_contents(base_path('docs/README.md')));
    $faltan = [];

    foreach (glob(base_path('modules/*.php')) as $manifiesto) {
        $modulo = basename($manifiesto, '.php');
        $datos = require $manifiesto;
        if (($datos['core'] ?? true) !== false) {
            continue;
        }

        foreach ($datos['files'] ?? [] as $archivo) {
            if (! preg_match('#^docs/([a-z0-9_-]+\.md)$#', $archivo, $m) || $m[1] === 'modules.md') {
                continue;
            }

            $conMarcador = collect($lineas)->contains(
                fn (string $l) => str_contains($l, "]({$m[1]})") && str_contains($l, "<!-- module:{$modulo} -->")
            );
            if (! $conMarcador) {
                $faltan[] = "{$m[1]} ({$modulo})";
            }
        }
    }

    // Sin el marcador, `module:remove` borra el doc y deja el enlace roto en el índice.
    expect($faltan)->toBe([]);
});
