<?php

use App\Contracts\PdfRendererInterface;

test('genera un PDF válido a partir de HTML', function () {
    $pdf = app(PdfRendererInterface::class)->render('<h1>Hola ñandú</h1><p>Informe</p>', ['paper' => 'a4', 'orientation' => 'landscape']);

    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()->and(strlen($pdf))->toBeGreaterThan(500);
});

test('rechaza papel u orientación no válidos', function () {
    app(PdfRendererInterface::class)->render('<p>x</p>', ['paper' => 'gigante']);
})->throws(InvalidArgumentException::class);

test('no descarga recursos remotos', function () {
    $pdf = app(PdfRendererInterface::class)->render('<img src="http://127.0.0.1:1/x.png"><p>ok</p>');

    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue();
});
