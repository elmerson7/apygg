<?php

use App\Services\TokenService;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

/**
 * Crea un TokenService cuyo JWTAuth lanza la excepción dada al validar.
 */
function tokenServiceQueLanza(Throwable $excepcion): TokenService
{
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('setToken')->andReturnSelf();
    $jwt->shouldReceive('parseToken')->andThrow($excepcion);

    return new TokenService($jwt);
}

/**
 * Captura el mensaje de aviso que registra validateToken en el canal `auth`.
 */
function mensajeAvisadoAlValidar(Throwable $excepcion): ?string
{
    $mensaje = null;
    $canal = Mockery::mock();
    $canal->shouldReceive('warning')->andReturnUsing(function (string $texto) use (&$mensaje) {
        $mensaje = $texto;
    });
    Log::shouldReceive('channel')->with('auth')->andReturn($canal);

    $resultado = tokenServiceQueLanza($excepcion)->validateToken('token');
    expect($resultado)->toBeFalse();

    return $mensaje;
}

test('un token en la lista negra se registra como tal y no como token inválido', function () {
    expect(mensajeAvisadoAlValidar(new TokenBlacklistedException))->toBe('Token en blacklist');
});

test('un token inválido se registra como inválido', function () {
    expect(mensajeAvisadoAlValidar(new TokenInvalidException))->toBe('Token inválido');
});

test('un token expirado se registra como expirado', function () {
    expect(mensajeAvisadoAlValidar(new TokenExpiredException))->toBe('Token expirado');
});
