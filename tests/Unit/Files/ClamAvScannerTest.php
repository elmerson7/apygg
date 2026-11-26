<?php

use App\Integrations\Antivirus\ClamAvScanner;

/*
 * Protocolo INSTREAM de clamd sin servidor real: un par de sockets locales hace de clamd (la respuesta se deja escrita
 * antes) y después se lee lo que envió el escáner. Contra clamd real: tests/Integration/ClamAvIntegracionTest.php.
 */

/** @return array{0: ClamAvScanner, 1: resource} escáner conectado a un "clamd" que responde $respuesta */
function clamdFalso(string $respuesta): array
{
    [$cliente, $servidor] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($servidor, $respuesta);

    return [new ClamAvScanner(fn () => $cliente), $servidor];
}

function contenido(string $texto)
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $texto);
    rewind($stream);

    return $stream;
}

test('envía zINSTREAM, el contenido en bloques con su longitud y el bloque vacío final', function () {
    [$escaner, $servidor] = clamdFalso("stream: OK\0");

    $veredicto = $escaner->scan(contenido('hola'), 4);

    expect($veredicto->isClean())->toBeTrue()
        ->and(stream_get_contents($servidor))->toBe("zINSTREAM\0".pack('N', 4).'hola'.pack('N', 0));
});

test('"FOUND" es infectado con el nombre de la firma', function () {
    [$escaner] = clamdFalso("stream: Eicar-Signature FOUND\0");

    $veredicto = $escaner->scan(contenido('x'), 1);

    expect($veredicto->isInfected())->toBeTrue()
        ->and($veredicto->detail)->toBe('clamav:Eicar-Signature');
});

test('superar StreamMaxLength es un error que no se arregla reintentando', function () {
    [$escaner] = clamdFalso("INSTREAM size limit exceeded. ERROR\0");

    $veredicto = $escaner->scan(contenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()
        ->and($veredicto->retryable)->toBeFalse();
});

test('una respuesta vacía o desconocida es un error reintentable', function (string $respuesta) {
    [$escaner] = clamdFalso($respuesta);

    $veredicto = $escaner->scan(contenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()
        ->and($veredicto->retryable)->toBeTrue();
})->with(['vacía' => [''], 'rara' => ["algo raro\0"]]);

test('sin conexión con clamd es un error reintentable, nunca limpio', function () {
    $veredicto = (new ClamAvScanner(fn () => false))->scan(contenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()
        ->and($veredicto->retryable)->toBeTrue()
        ->and($veredicto->detail)->toContain('conectar');
});

test('sin conector inyectado usa CLAMAV_HOST y CLAMAV_PORT y falla cerrado si no hay clamd', function () {
    config(['files.scanning.clamav.host' => '127.0.0.1', 'files.scanning.clamav.port' => 1, 'files.scanning.clamav.connect_timeout' => 1]);

    $veredicto = (new ClamAvScanner)->scan(contenido('x'), 1);

    expect($veredicto->isError())->toBeTrue()->and($veredicto->retryable)->toBeTrue();
});

test('un archivo mayor que un bloque se envía en varios bloques', function () {
    [$escaner, $servidor] = clamdFalso("stream: OK\0");
    $grande = str_repeat('a', 70000);

    $escaner->scan(contenido($grande), 70000);
    $enviado = stream_get_contents($servidor);

    expect($enviado)->toBe("zINSTREAM\0".pack('N', 65536).substr($grande, 0, 65536).pack('N', 4464).substr($grande, 65536).pack('N', 0));
});
