<?php

test('backup:create --files falla con error claro en vez de aceptar la opción en silencio', function () {
    $this->artisan('backup:create', ['--files' => true])
        ->expectsOutputToContain('--files no está soportado')
        ->assertExitCode(1);
});
