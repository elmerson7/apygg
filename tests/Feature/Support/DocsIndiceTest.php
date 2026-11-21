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
