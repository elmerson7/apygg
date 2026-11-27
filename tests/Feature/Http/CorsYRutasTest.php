<?php

test('una ruta que no existe responde 404 con su código y no 405', function () {
    $this->getJson('/ruta-que-no-existe-'.uniqid())
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

test('el preflight OPTIONS de un origen permitido responde 204 con cabeceras CORS en cualquier ruta', function () {
    config(['cors.allowed_origins' => ['https://app.example.test']]);

    $respuesta = $this->call('OPTIONS', '/users', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.example.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($respuesta->getStatusCode())->toBe(204)
        ->and($respuesta->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.test');
});

test('el preflight de un origen no permitido no recibe cabecera de origen permitido', function () {
    config(['cors.allowed_origins' => ['https://app.example.test']]);

    $respuesta = $this->call('OPTIONS', '/users', [], [], [], [
        'HTTP_ORIGIN' => 'https://malicioso.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($respuesta->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://malicioso.example');
});

test('una respuesta normal a un origen permitido lleva la cabecera CORS', function () {
    config(['cors.allowed_origins' => ['https://app.example.test']]);

    $this->getJson('/health', ['Origin' => 'https://app.example.test'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');
});

test('un comodín de subdominio acepta subdominios pero no un dominio que solo termina igual', function (string $origen, bool $permitido) {
    config(['cors.allowed_origins' => ['*.example.test']]);

    $cabecera = $this->getJson('/health', ['Origin' => $origen])->headers->get('Access-Control-Allow-Origin');

    expect($cabecera === $origen)->toBe($permitido);
})->with([
    ['https://app.example.test', true],
    ['https://a.b.example.test', true],
    ['https://evilexample.test', false],
    ['https://example.test.evil.org', false],
]);
