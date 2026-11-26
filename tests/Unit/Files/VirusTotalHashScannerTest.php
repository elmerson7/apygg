<?php

use App\Integrations\Antivirus\VirusTotalHashScanner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Motor VirusTotal: solo consulta el SHA-256 (nunca sube el archivo). Respuestas simuladas con Http::fake.
 */

beforeEach(function () {
    config(['files.scanning.virustotal.key' => 'clave-de-prueba', 'files.scanning.virustotal.min_detections' => 2]);
});

function vtContenido(string $texto)
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $texto);
    rewind($stream);

    return $stream;
}

function vtRespuesta(int $maliciosos): array
{
    return ['data' => ['attributes' => ['last_analysis_stats' => ['malicious' => $maliciosos, 'harmless' => 60]]]];
}

test('consulta GET /files/{sha256} con la clave en x-apikey y sin enviar el contenido', function () {
    Http::fake(['*' => Http::response(vtRespuesta(0))]);

    (new VirusTotalHashScanner)->scan(vtContenido('hola'), 4);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === 'https://www.virustotal.com/api/v3/files/'.hash('sha256', 'hola')
        && $r->header('x-apikey') === ['clave-de-prueba']
        && $r->body() === '');
});

test('con min_detections motores o más es infectado', function () {
    Http::fake(['*' => Http::response(vtRespuesta(3))]);

    $veredicto = (new VirusTotalHashScanner)->scan(vtContenido('x'), 1);

    expect($veredicto->isInfected())->toBeTrue()->and($veredicto->detail)->toBe('virustotal:3 motores');
});

test('por debajo de min_detections es limpio (un falso positivo aislado no bloquea)', function () {
    Http::fake(['*' => Http::response(vtRespuesta(1))]);

    expect((new VirusTotalHashScanner)->scan(vtContenido('x'), 1)->isClean())->toBeTrue();
});

test('un hash desconocido (404) cuenta como limpio y lo anota', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'NotFoundError']], 404)]);

    $veredicto = (new VirusTotalHashScanner)->scan(vtContenido('x'), 1);

    expect($veredicto->isClean())->toBeTrue()->and($veredicto->detail)->toContain('desconocido');
});

test('el límite de peticiones y los fallos del servicio son errores reintentables', function (int $status) {
    Http::fake(['*' => Http::response([], $status)]);

    $veredicto = (new VirusTotalHashScanner)->scan(vtContenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()->and($veredicto->retryable)->toBeTrue();
})->with([429, 500, 503]);

test('una clave rechazada o ausente es un error que no se arregla reintentando', function () {
    Http::fake(['*' => Http::response([], 401)]);
    expect((new VirusTotalHashScanner)->scan(vtContenido('x'), 1)->retryable)->toBeFalse();

    config(['files.scanning.virustotal.key' => null]);
    $veredicto = (new VirusTotalHashScanner)->scan(vtContenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()->and($veredicto->retryable)->toBeFalse();
    Http::assertSentCount(1);
});

test('sin conexión es un error reintentable', function () {
    Http::fake(fn () => throw new ConnectionException('caído'));

    $veredicto = (new VirusTotalHashScanner)->scan(vtContenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()->and($veredicto->retryable)->toBeTrue();
});
