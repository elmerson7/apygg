<?php

// U1: Composer corta los scripts a los 300 s (process-timeout). La suite completa tarda más: `make test` daba un
// falso rojo y dejaba pest huérfano en el contenedor.

test('los scripts de pruebas de composer desactivan el límite de tiempo de composer', function (string $script) {
    $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'];

    expect($scripts[$script][0] ?? null)->toBe('Composer\\Config::disableProcessTimeout');
})->with(['test', 'test:watch', 'test:parallel']);
