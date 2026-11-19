<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Arranca la app de nuevo con BACKOFFICE_ENABLED=false (se lee al registrar el provider). */
function withBackofficeDisabled(): void
{
    putenv('BACKOFFICE_ENABLED=false');
    $_ENV['BACKOFFICE_ENABLED'] = $_SERVER['BACKOFFICE_ENABLED'] = 'false';

    test()->refreshApplication();
}

afterEach(function () {
    putenv('BACKOFFICE_ENABLED=true');
    $_ENV['BACKOFFICE_ENABLED'] = $_SERVER['BACKOFFICE_ENABLED'] = 'true';
});

test('con BACKOFFICE_ENABLED=false la ruta del panel no existe', function () {
    withBackofficeDisabled();

    expect(config('backoffice.enabled'))->toBeFalse()
        ->and(Filament::getPanels())->toBe([]);

    // La API tiene su propio comodín (404/405 en JSON): lo que importa es que no sea el panel.
    expect($this->get('/backoffice')->status())->toBeIn([404, 405]);
    expect($this->get('/backoffice/login')->status())->toBeIn([404, 405]);
});

test('con BACKOFFICE_ENABLED=false no se registra ninguna ruta del panel', function () {
    withBackofficeDisabled();

    $rutas = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'backoffice'));

    expect($rutas)->toHaveCount(0);
});

test('la ruta del panel es configurable y no choca con el prefijo admin de la API', function () {
    expect(config('backoffice.path'))->toBe('backoffice')
        ->and(config('backoffice.path'))->not->toBe('admin');
});
