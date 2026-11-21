<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_prueba/deprecada', fn () => response()->json(['ok' => true]))
        ->middleware('deprecated:2027-03-01,https://docs.example.test/migrar');
    Route::get('/_prueba/deprecada-sin-link', fn () => response()->json(['ok' => true]))
        ->middleware('deprecated:2027-03-01T10:30:00Z');
    Route::get('/_prueba/vigente', fn () => response()->json(['ok' => true]));
});

test('una ruta deprecada añade Deprecation, Sunset y Link sin cambiar la respuesta', function () {
    $this->getJson('/_prueba/deprecada')
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertHeader('Deprecation', 'true')
        ->assertHeader('Sunset', 'Mon, 01 Mar 2027 00:00:00 GMT')
        ->assertHeader('Link', '<https://docs.example.test/migrar>; rel="deprecation"');
});

test('la fecha Sunset se normaliza a GMT y el Link es opcional', function () {
    $res = $this->getJson('/_prueba/deprecada-sin-link')->assertOk();

    expect($res->headers->get('Sunset'))->toBe('Mon, 01 Mar 2027 10:30:00 GMT')
        ->and($res->headers->has('Link'))->toBeFalse();
});

test('una ruta no deprecada no lleva las cabeceras', function () {
    $res = $this->getJson('/_prueba/vigente')->assertOk();

    expect($res->headers->has('Deprecation'))->toBeFalse()
        ->and($res->headers->has('Sunset'))->toBeFalse();
});

test('las cabeceras de deprecación se exponen a clientes de otro origen (CORS)', function () {
    expect(config('cors.exposed_headers'))->toContain('Deprecation', 'Sunset', 'Link');
});
