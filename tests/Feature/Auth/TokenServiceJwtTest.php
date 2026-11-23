<?php

use App\Contracts\TokenServiceInterface;
use App\Models\Blacklist;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

uses(RefreshDatabase::class);

function tokens(): TokenServiceInterface
{
    // Instancia nueva con el JWTAuth del contenedor: sin token residual de otra llamada.
    app('tymon.jwt')->unsetToken();

    return app(TokenServiceInterface::class);
}

/** parseToken() lee el token del request: el argumento explícito solo vale si la petición lo trae. */
function enCabecera(string $jwt): void
{
    request()->headers->set('Authorization', 'Bearer '.$jwt);
    app('tymon.jwt')->unsetToken();
}

function claimsDe(string $jwt): array
{
    app('tymon.jwt')->unsetToken();

    return app('tymon.jwt')->setToken($jwt)->getPayload()->toArray();
}

test('el access token lleva type access, la versión de tokens y los claims extra, sin dejar que se pise el tipo', function () {
    $user = User::factory()->create(['token_version' => 3]);

    $claims = claimsDe(tokens()->generateAccessToken($user, ['sid' => 'abc', 'type' => 'refresh', 'extra' => 'x']));

    expect($claims['type'])->toBe('access')
        ->and($claims['tv'])->toBe(3)
        ->and($claims['sid'])->toBe('abc')
        ->and($claims['extra'])->toBe('x')
        ->and((string) $claims['sub'])->toBe((string) $user->id);
});

test('el refresh token caduca según jwt.refresh_ttl, restaura jwt.ttl y no deja pisar type ni exp', function () {
    config(['jwt.ttl' => 7, 'jwt.refresh_ttl' => 1000]);
    $user = User::factory()->create();
    $this->travelTo(now()->startOfSecond());

    $claims = claimsDe(tokens()->generateRefreshToken($user, ['sid' => 's1', 'jti' => 'j1', 'type' => 'access', 'exp' => 1]));

    expect($claims['type'])->toBe('refresh')
        ->and($claims['exp'])->toBe(now()->addMinutes(1000)->timestamp)
        ->and($claims['sid'])->toBe('s1')
        ->and($claims['jti'])->toBe('j1')
        ->and(config('jwt.ttl'))->toBe(7);
});

test('si el JWT falla al generar el refresh se relanza y jwt.ttl queda restaurado; el access también relanza', function () {
    config(['jwt.ttl' => 9, 'jwt.refresh_ttl' => 500]);
    $user = User::factory()->create();

    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('customClaims')->andThrow(new JWTException('roto'));
    $servicio = new TokenService($jwt);

    expect(fn () => $servicio->generateRefreshToken($user))->toThrow(JWTException::class, 'roto');
    expect(config('jwt.ttl'))->toBe(9);
    expect(fn () => $servicio->generateAccessToken($user))->toThrow(JWTException::class, 'roto');
});

test('generateTokens da access y refresh de tipos distintos; las de sesión llevan el sid y el jti', function () {
    $user = User::factory()->create();

    $par = tokens()->generateTokens($user);
    expect(array_keys($par))->toBe(['access_token', 'refresh_token'])
        ->and(claimsDe($par['access_token'])['type'])->toBe('access')
        ->and(claimsDe($par['refresh_token'])['type'])->toBe('refresh')
        ->and(claimsDe($par['access_token']))->not->toHaveKey('sid');

    $sesion = tokens()->generateSessionTokens($user, 'sid-1', 'jti-1');
    $a = claimsDe($sesion['access_token']);
    $r = claimsDe($sesion['refresh_token']);
    expect($a['sid'])->toBe('sid-1')->and($a['type'])->toBe('access')->and($a)->not->toHaveKey('jti_refresh')
        ->and($r['sid'])->toBe('sid-1')->and($r['jti'])->toBe('jti-1')->and($r['type'])->toBe('refresh');
});

test('validateToken acepta un token válido y rechaza uno malformado o revocado', function () {
    $user = User::factory()->create();
    $token = tokens()->generateAccessToken($user);

    enCabecera($token);
    expect(tokens()->validateToken($token))->toBeTrue();

    enCabecera('no.es.un-jwt');
    expect(tokens()->validateToken('no.es.un-jwt'))->toBeFalse();

    enCabecera($token);
    tokens()->revokeToken($token);
    expect(tokens()->validateToken($token))->toBeFalse();
});

test('validateToken y getUserFromToken sin token propio usan el del request', function () {
    $user = User::factory()->create();
    $token = tokens()->generateAccessToken($user);

    request()->headers->set('Authorization', 'Bearer '.$token);
    app('tymon.jwt')->unsetToken();

    expect(tokens()->validateToken())->toBeTrue()
        ->and(tokens()->getUserFromToken()?->id)->toBe($user->id)
        ->and(array_key_exists('sub', tokens()->getTokenClaims()))->toBeTrue();
});

test('getUserFromToken devuelve el usuario o null; getTokenClaims devuelve los claims o lanza', function () {
    $user = User::factory()->create();
    $token = tokens()->generateAccessToken($user);

    enCabecera($token);
    expect(tokens()->getUserFromToken($token)?->id)->toBe($user->id)
        ->and((string) tokens()->getTokenClaims($token)['sub'])->toBe((string) $user->id);

    enCabecera('basura');
    expect(tokens()->getUserFromToken('basura'))->toBeNull();
    expect(fn () => tokens()->getTokenClaims('basura'))->toThrow(JWTException::class);
});

test('revokeToken revoca, es idempotente con un token ya revocado y lanza con uno inválido', function () {
    $user = User::factory()->create();
    $token = tokens()->generateAccessToken($user);

    enCabecera($token);
    expect(tokens()->revokeToken($token))->toBeTrue();
    enCabecera($token);
    expect(tokens()->getUserFromToken($token))->toBeNull();
    enCabecera($token);
    expect(tokens()->revokeToken($token))->toBeTrue();

    enCabecera('basura');
    expect(fn () => tokens()->revokeToken('basura'))->toThrow(JWTException::class);
});

test('revokeToken sin ningún token devuelve false', function () {
    request()->headers->remove('Authorization');
    app('tymon.jwt')->unsetToken();

    expect(tokens()->revokeToken())->toBeFalse();
});

test('revokeRefreshToken solo revoca refresh propios y devuelve false en el resto', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);
    $access = tokens()->generateAccessToken($user);

    expect(tokens()->revokeRefreshToken($refresh, $otro))->toBeFalse();
    enCabecera($refresh);
    expect(tokens()->getUserFromToken($refresh))->not->toBeNull();

    expect(tokens()->revokeRefreshToken($access, $user))->toBeFalse();
    enCabecera($access);
    expect(tokens()->getUserFromToken($access))->not->toBeNull();

    expect(tokens()->revokeRefreshToken('basura', $user))->toBeFalse();

    expect(tokens()->revokeRefreshToken($refresh, $user))->toBeTrue();
    enCabecera($refresh);
    expect(tokens()->getUserFromToken($refresh))->toBeNull();
    request()->headers->remove('Authorization');
    expect(tokens()->revokeRefreshToken($refresh, $user))->toBeFalse();

    // No deja el token puesto en el JWTAuth compartido.
    expect(app('tymon.jwt')->getToken())->toBeNull();
});

test('revokeRefreshToken con un refresh caducado devuelve false sin lanzar', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);

    $this->travel(config('jwt.refresh_ttl', 20160) + 5)->minutes();

    expect(tokens()->revokeRefreshToken($refresh, $user))->toBeFalse();
});

test('validateRefreshToken devuelve claims y usuario de un refresh válido', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user, ['sid' => 'S', 'jti' => 'J']);

    $r = tokens()->validateRefreshToken($refresh);

    expect($r['user']->id)->toBe($user->id)
        ->and($r['claims']['sid'])->toBe('S')
        ->and($r['claims']['type'])->toBe('refresh');
});

test('validateRefreshToken exige token, de tipo refresh, con firma y no caducado', function () {
    $user = User::factory()->create();

    expect(fn () => tokens()->validateRefreshToken(null))->toThrow(TokenInvalidException::class, 'Refresh token no proporcionado');
    expect(fn () => tokens()->validateRefreshToken(''))->toThrow(TokenInvalidException::class, 'Refresh token no proporcionado');
    expect(fn () => tokens()->validateRefreshToken(tokens()->generateAccessToken($user)))
        ->toThrow(TokenInvalidException::class, 'no es un refresh token');
    expect(fn () => tokens()->validateRefreshToken('basura'))->toThrow(TokenInvalidException::class);

    $refresh = tokens()->generateRefreshToken($user);
    $this->travel(config('jwt.refresh_ttl', 20160) + 5)->minutes();
    expect(fn () => tokens()->validateRefreshToken($refresh))->toThrow(TokenExpiredException::class);
});

test('validateRefreshToken rechaza un refresh de usuario borrado, con versión de tokens vieja o bloqueado', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);

    $user->revokeAllTokens();
    expect(fn () => tokens()->validateRefreshToken($refresh))->toThrow(TokenInvalidException::class, 'Sesión revocada');

    $nuevo = tokens()->generateRefreshToken($user->refresh());
    expect(tokens()->validateRefreshToken($nuevo)['user']->id)->toBe($user->id);

    Blacklist::create(['user_id' => $user->id, 'reason' => 'abuso']);
    expect(fn () => tokens()->validateRefreshToken($nuevo))->toThrow(TokenInvalidException::class, 'Sesión no permitida');

    $otro = User::factory()->create();
    $refreshOtro = tokens()->generateRefreshToken($otro);
    $otro->forceDelete();
    expect(fn () => tokens()->validateRefreshToken($refreshOtro))->toThrow(TokenInvalidException::class, 'Usuario no encontrado');
});

test('validateRefreshToken restaura la cabecera Authorization original o la quita si no había', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);

    request()->headers->set('Authorization', 'Bearer original');
    tokens()->validateRefreshToken($refresh);
    expect(request()->header('Authorization'))->toBe('Bearer original');

    request()->headers->remove('Authorization');
    tokens()->validateRefreshToken($refresh);
    expect(request()->headers->has('Authorization'))->toBeFalse();

    expect(fn () => tokens()->validateRefreshToken('basura'))->toThrow(TokenInvalidException::class);
    expect(request()->headers->has('Authorization'))->toBeFalse();
});

test('refreshToken rota: devuelve tokens y usuario nuevos y el refresh usado deja de valer', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);

    $r = tokens()->refreshToken($refresh);

    expect($r['user']->id)->toBe($user->id)
        ->and(claimsDe($r['access_token'])['type'])->toBe('access')
        ->and(claimsDe($r['refresh_token'])['type'])->toBe('refresh')
        ->and($r['refresh_token'])->not->toBe($refresh);

    expect(fn () => tokens()->refreshToken($refresh))->toThrow(JWTException::class);
    expect(fn () => tokens()->refreshToken(null))->toThrow(TokenInvalidException::class);
    expect(fn () => tokens()->refreshToken(tokens()->generateAccessToken($user)))->toThrow(TokenInvalidException::class);
});

test('las expiraciones se devuelven en segundos a partir de la configuración', function () {
    config(['jwt.ttl' => 15, 'jwt.refresh_ttl' => 100]);
    expect(tokens()->getTokenExpiration())->toBe(900)
        ->and(tokens()->getRefreshTokenExpiration())->toBe(6000);
});
