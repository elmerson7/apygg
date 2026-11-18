<?php

use App\Http\Middleware\TransformRequestMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

function transformar(array $query): array
{
    $request = Request::create('/x', 'GET', $query);
    (new TransformRequestMiddleware)->handle($request, fn () => new Response('ok'));

    return $request->query->all();
}

test('no convierte UUID a número', function () {
    $uuid = '12345678-1234-1234-1234-123456789012';

    expect(transformar(['id' => $uuid, 'ref' => $uuid]))->toMatchArray(['id' => $uuid, 'ref' => $uuid]);
});

test('conserva los ceros a la izquierda en cadenas numéricas genéricas', function () {
    expect(transformar(['phone' => '0987654321', 'zip' => '007']))
        ->toMatchArray(['phone' => '0987654321', 'zip' => '007']);
});

test('conserva números muy largos y notación científica como texto', function () {
    expect(transformar(['card' => '12345678901234567890', 'code' => '1e5']))
        ->toMatchArray(['card' => '12345678901234567890', 'code' => '1e5']);
});

test('sigue convirtiendo números canónicos y campos numéricos conocidos', function () {
    expect(transformar(['amount' => '12.50', 'quantity' => '3', 'rating' => '42']))
        ->toMatchArray(['amount' => 12.5, 'quantity' => 3, 'rating' => 42]);
});
