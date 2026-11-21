<?php

use App\Support\Jwt\KeyRotatingLcobucci;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci;

/*
 * JWT asimétrico con `kid` y rotación de claves (docs/jwt-keys.md). Los pares de claves se generan en el test.
 */

/** @return array{private: string, public: string} */
function parRsa(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $private);

    return ['private' => $private, 'public' => openssl_pkey_get_details($res)['key']];
}

/** @param  array{private: string, public: string}  $par */
function proveedorRs256(array $par, ?string $kid = 'k1', ?array $anterior = null): KeyRotatingLcobucci
{
    config([
        'jwt.algo' => 'RS256',
        'jwt.keys' => ['public' => $par['public'], 'private' => $par['private'], 'passphrase' => null],
        'jwt.kid' => $kid,
        'jwt.previous_public_key' => $anterior['public'] ?? null,
        'jwt.previous_public_key_until' => $anterior['until'] ?? null,
    ]);

    return new KeyRotatingLcobucci;
}

function cargaJwt(): array
{
    return ['sub' => '1', 'jti' => 'abc', 'iat' => time(), 'exp' => time() + 300];
}

test('sin configurar nada el algoritmo es HS256 y el proveedor es el de la librería, sin kid', function () {
    expect(config('jwt.algo'))->toBe('HS256')
        ->and(config('jwt.providers.jwt'))->toBe(Lcobucci::class)
        ->and(config('jwt.kid'))->toBeNull();

    $token = app('tymon.jwt.provider.jwt')->encode(cargaJwt());
    $cabecera = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

    expect($cabecera['alg'])->toBe('HS256')->and($cabecera)->not->toHaveKey('kid');
});

test('con RS256 y JWT_KID el token lleva la cabecera kid y se verifica con la clave pública', function () {
    $par = parRsa();
    $proveedor = proveedorRs256($par);

    $token = $proveedor->encode(cargaJwt());
    $cabecera = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

    expect($cabecera['alg'])->toBe('RS256')
        ->and($cabecera['kid'])->toBe('k1')
        ->and($proveedor->decode($token)['jti'])->toBe('abc');
});

test('un token firmado con otra clave se rechaza', function () {
    $ajena = proveedorRs256(parRsa(), 'x');
    $token = $ajena->encode(cargaJwt());

    $propio = proveedorRs256(parRsa(), 'k1');

    expect(fn () => $propio->decode($token))->toThrow(TokenInvalidException::class);
});

test('en la rotación se acepta la clave anterior solo dentro de la ventana', function () {
    $vieja = parRsa();
    $nueva = parRsa();
    $tokenViejo = proveedorRs256($vieja, 'k1')->encode(cargaJwt());

    $dentro = proveedorRs256($nueva, 'k2', ['public' => $vieja['public'], 'until' => now()->addHour()->toIso8601String()]);
    $fuera = proveedorRs256($nueva, 'k2', ['public' => $vieja['public'], 'until' => now()->subHour()->toIso8601String()]);
    $sinVentana = proveedorRs256($nueva, 'k2');

    expect($dentro->decode($tokenViejo)['jti'])->toBe('abc')
        ->and(fn () => $fuera->decode($tokenViejo))->toThrow(TokenInvalidException::class)
        ->and(fn () => $sinVentana->decode($tokenViejo))->toThrow(TokenInvalidException::class);

    // Los tokens nuevos salen con el kid nuevo y los acepta el mismo proveedor.
    $tokenNuevo = $dentro->encode(cargaJwt());
    $cabecera = json_decode(base64_decode(strtr(explode('.', $tokenNuevo)[0], '-_', '+/')), true);

    expect($cabecera['kid'])->toBe('k2')->and($dentro->decode($tokenNuevo)['jti'])->toBe('abc');
});

test('un token HS256 no se acepta en un proveedor RS256 (sin confusión de algoritmo)', function () {
    $hs = new Lcobucci('secreto-de-prueba-muy-largo-0123456789', 'HS256', ['public' => null, 'private' => null]);
    $token = $hs->encode(cargaJwt());

    expect(fn () => proveedorRs256(parRsa())->decode($token))->toThrow(TokenInvalidException::class);
});
