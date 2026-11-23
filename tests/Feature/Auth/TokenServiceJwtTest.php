<?php

use App\Contracts\TokenServiceInterface;
use App\Models\Blacklist;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
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

// ───────────── Registro (canal auth/security), configuración y revocación definitiva ─────────────

/**
 * Captura lo registrado por canal: lista de [canal, nivel, mensaje, contexto].
 *
 * @return ArrayObject<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
 */
function logsDeTokens(): ArrayObject
{
    $registro = new ArrayObject;
    Log::shouldReceive('channel')->andReturnUsing(function (string $canal) use ($registro) {
        return new class($canal, $registro)
        {
            public function __construct(private string $canal, private ArrayObject $registro) {}

            /** @param array{0: string, 1?: array<string, mixed>} $args */
            public function __call(string $nivel, array $args): void
            {
                $this->registro[] = [$this->canal, $nivel, (string) $args[0], (array) ($args[1] ?? [])];
            }
        };
    });

    return $registro;
}

/** @param array<string, mixed> $claims */
function jwtConClaims(array $claims): MockInterface
{
    $payload = Mockery::mock();
    $payload->shouldReceive('toArray')->andReturn($claims);
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('setToken')->andReturnSelf();
    $jwt->shouldReceive('parseToken')->andReturnSelf();
    $jwt->shouldReceive('getPayload')->andReturn($payload);

    return $jwt;
}

test('generar un access token registra en debug el usuario y el TTL', function () {
    config(['jwt.ttl' => 33]);
    $user = User::factory()->create();
    $logs = logsDeTokens();

    tokens()->generateAccessToken($user);

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('auth')->and($logs[0][1])->toBe('debug')
        ->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'token_ttl' => 33]);
});

test('generar un refresh token registra el TTL de refresh y su caducidad con formato de fecha', function () {
    config(['jwt.refresh_ttl' => 700]);
    $user = User::factory()->create();
    $this->travelTo(now()->startOfSecond());
    $logs = logsDeTokens();

    tokens()->generateRefreshToken($user);

    expect($logs)->toHaveCount(1)
        ->and($logs[0][1])->toBe('debug')
        ->and($logs[0][3])->toMatchArray([
            'user_id' => $user->id,
            'token_ttl' => 700,
            'expires_at' => date('Y-m-d H:i:s', now()->addMinutes(700)->timestamp),
        ]);
});

test('mientras se firma el refresh jwt.ttl vale el TTL de refresh', function () {
    config(['jwt.ttl' => 9, 'jwt.refresh_ttl' => 500]);
    $user = User::factory()->create();
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('customClaims')->andReturnSelf();
    $jwt->shouldReceive('fromUser')->andReturnUsing(fn () => 'ttl='.config('jwt.ttl'));

    expect((new TokenService($jwt))->generateRefreshToken($user))->toBe('ttl=500')
        ->and(config('jwt.ttl'))->toBe(9);
});

test('un fallo del JWT al generar tokens se registra como error con el usuario y el mensaje', function () {
    $user = User::factory()->create();
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('customClaims')->andThrow(new JWTException('roto'));
    $logs = logsDeTokens();

    expect(fn () => (new TokenService($jwt))->generateAccessToken($user))->toThrow(JWTException::class);
    expect(fn () => (new TokenService($jwt))->generateRefreshToken($user))->toThrow(JWTException::class);

    expect($logs)->toHaveCount(2)
        ->and($logs[0][1])->toBe('error')->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'error' => 'roto'])
        ->and($logs[1][1])->toBe('error')->and($logs[1][3])->toMatchArray(['user_id' => $user->id, 'error' => 'roto']);
});

test('validateToken registra un aviso con el error según el tipo de fallo y no consulta setToken sin token', function (Throwable $e, string $mensaje) {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldNotReceive('setToken');
    $jwt->shouldReceive('parseToken')->andThrow($e);
    $logs = logsDeTokens();

    expect((new TokenService($jwt))->validateToken())->toBeFalse();

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('auth')->and($logs[0][1])->toBe('warning')
        ->and($logs[0][2])->toBe($mensaje)
        ->and($logs[0][3])->toMatchArray(['error' => $e->getMessage()]);
})->with([
    'expirado' => [fn () => new TokenExpiredException('caducó'), 'Token expirado'],
    'lista negra' => [fn () => new TokenBlacklistedException('negro'), 'Token en blacklist'],
    'inválido' => [fn () => new TokenInvalidException('mal'), 'Token inválido'],
    'otro' => [fn () => new JWTException('otro'), 'Error al validar token'],
]);

test('getUserFromToken devuelve null si el JWT autentica algo que no es un User', function () {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('parseToken')->andReturnSelf();
    $jwt->shouldReceive('authenticate')->andReturn(false);

    expect((new TokenService($jwt))->getUserFromToken())->toBeNull();
});

test('getTokenClaims registra el error con su mensaje antes de relanzar', function () {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('parseToken')->andThrow(new JWTException('sin claims'));
    $logs = logsDeTokens();

    expect(fn () => (new TokenService($jwt))->getTokenClaims())->toThrow(JWTException::class);

    expect($logs)->toHaveCount(1)
        ->and($logs[0][1])->toBe('error')->and($logs[0][3])->toMatchArray(['error' => 'sin claims']);
});

test('revokeToken con un token ya en lista negra al leer sus claims devuelve true y lo registra', function () {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('getToken')->andReturn('tok');
    $jwt->shouldReceive('parseToken')->andThrow(new TokenBlacklistedException('ya'));
    $jwt->shouldNotReceive('invalidate');
    $logs = logsDeTokens();

    expect((new TokenService($jwt))->revokeToken())->toBeTrue();

    expect($logs)->toHaveCount(1)
        ->and($logs[0][1])->toBe('info')->and($logs[0][2])->toBe('Token ya estaba revocado (blacklisted)')
        ->and($logs[0][3])->toMatchArray(['error' => 'ya']);
});

test('revokeToken sigue invalidando aunque no pueda leer los claims y registra el aviso', function () {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('getToken')->andReturn('tok');
    $jwt->shouldReceive('parseToken')->andThrow(new JWTException('ilegible'));
    $jwt->shouldReceive('invalidate')->once()->with(true);
    $logs = logsDeTokens();

    expect((new TokenService($jwt))->revokeToken())->toBeTrue();

    expect($logs)->toHaveCount(2)
        ->and($logs[0][1])->toBe('warning')->and($logs[0][3])->toMatchArray(['error' => 'ilegible'])
        ->and($logs[1][1])->toBe('info')->and($logs[1][2])->toBe('Token revocado exitosamente')
        ->and($logs[1][3])->toMatchArray(['user_id' => null, 'jti' => null]);
});

test('revokeToken registra usuario y jti al revocar y el usuario si ya estaba revocado al invalidar', function () {
    $jwt = jwtConClaims(['sub' => '5', 'jti' => 'abc']);
    $jwt->shouldReceive('getToken')->andReturn('tok');
    $jwt->shouldReceive('invalidate')->once()->with(true);
    $logs = logsDeTokens();

    expect((new TokenService($jwt))->revokeToken())->toBeTrue();
    expect($logs[0][2])->toBe('Token revocado exitosamente')
        ->and($logs[0][3])->toMatchArray(['user_id' => '5', 'jti' => 'abc']);

    $jwt2 = jwtConClaims(['sub' => '5']);
    $jwt2->shouldReceive('getToken')->andReturn('tok');
    $jwt2->shouldReceive('invalidate')->andThrow(new TokenBlacklistedException('doble'));
    $logs->exchangeArray([]);

    expect((new TokenService($jwt2))->revokeToken())->toBeTrue();
    expect($logs)->toHaveCount(1)
        ->and($logs[0][1])->toBe('info')->and($logs[0][3])->toMatchArray(['user_id' => '5', 'error' => 'doble']);
});

test('revokeToken registra y relanza un fallo general y trata la lista negra como éxito', function () {
    $roto = Mockery::mock(JWTAuth::class);
    $roto->shouldReceive('setToken')->andThrow(new JWTException('cae'));
    $negro = Mockery::mock(JWTAuth::class);
    $negro->shouldReceive('setToken')->andThrow(new TokenBlacklistedException('negro'));
    $logs = logsDeTokens();

    expect(fn () => (new TokenService($roto))->revokeToken('x'))->toThrow(JWTException::class);
    expect($logs[0][1])->toBe('error')->and($logs[0][3])->toMatchArray(['error' => 'cae']);

    expect((new TokenService($negro))->revokeToken('x'))->toBeTrue();
    expect($logs[1][1])->toBe('info')->and($logs[1][3])->toMatchArray(['error' => 'negro']);
});

test('la revocación es definitiva aunque haya periodo de gracia en la lista negra', function () {
    config(['jwt.blacklist_grace_period' => 60]);
    $user = User::factory()->create();

    $access = tokens()->generateAccessToken($user);
    enCabecera($access);
    tokens()->revokeToken($access);
    enCabecera($access);
    expect(tokens()->validateToken($access))->toBeFalse();

    $refresh = tokens()->generateRefreshToken($user);
    expect(tokens()->revokeRefreshToken($refresh, $user))->toBeTrue();
    expect(fn () => tokens()->validateRefreshToken($refresh))->toThrow(TokenInvalidException::class);

    $otro = tokens()->generateRefreshToken($user);
    tokens()->refreshToken($otro);
    expect(fn () => tokens()->validateRefreshToken($otro))->toThrow(TokenInvalidException::class);
});

test('validateRefreshToken registra el fallo de parseo con la huella de 12 caracteres y sin el token', function (Throwable $e, string $nivel, string $mensaje) {
    $refresh = 'cabecera.cuerpo.firmaSecreta';
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('parseToken')->andThrow($e);
    $logs = logsDeTokens();

    $lanzada = null;

    try {
        (new TokenService($jwt))->validateRefreshToken($refresh);
    } catch (JWTException $capturada) {
        $lanzada = $capturada;
    }
    expect($lanzada)->toBeInstanceOf(JWTException::class);

    expect($logs)->not->toBeEmpty()
        ->and($logs[0][0])->toBe('auth')->and($logs[0][1])->toBe($nivel)->and($logs[0][2])->toBe($mensaje)
        ->and($logs[0][3]['token_fingerprint'])->toBe(substr(hash('sha256', $refresh), 0, 12))
        ->and($logs[0][3]['error'])->toBe($e->getMessage())
        ->and(json_encode($logs->getArrayCopy()))->not->toContain('firmaSecreta');
})->with([
    'expirado' => [fn () => new TokenExpiredException('caducó'), 'warning', 'Refresh token expirado al parsear'],
    'inválido' => [fn () => new TokenInvalidException('mal'), 'error', 'Refresh token inválido al parsear'],
    'otro' => [fn () => new JWTException('otro'), 'error', 'Error al parsear refresh token'],
]);

test('validateRefreshToken convierte un fallo genérico del JWT en token inválido con su mensaje', function () {
    $jwt = Mockery::mock(JWTAuth::class);
    $jwt->shouldReceive('parseToken')->andThrow(new JWTException('rarísimo'));
    logsDeTokens();

    expect(fn () => (new TokenService($jwt))->validateRefreshToken('a.b.c'))
        ->toThrow(TokenInvalidException::class, 'Token inválido: rarísimo');
});

test('validateRefreshToken registra el aviso final con el error al caducar o ser inválido', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);
    $logs = logsDeTokens();

    expect(fn () => tokens()->validateRefreshToken(tokens()->generateAccessToken($user)))->toThrow(TokenInvalidException::class);

    $ultimo = $logs[count($logs) - 1];
    expect($ultimo[1])->toBe('warning')->and($ultimo[2])->toBe('Refresh token inválido')
        ->and($ultimo[3]['error'])->toContain('no es un refresh token');

    $this->travel(config('jwt.refresh_ttl') + 5)->minutes();
    $logs->exchangeArray([]);
    expect(fn () => tokens()->validateRefreshToken($refresh))->toThrow(TokenExpiredException::class);
    $ultimo = $logs[count($logs) - 1];
    expect($ultimo[2])->toBe('Refresh token expirado')->and($ultimo[3])->toHaveKey('error');
});

test('validateRefreshToken sin claim tv acepta a un usuario con versión 0 y sin sub rechaza', function () {
    $user = User::factory()->create(['token_version' => 0]);

    $ok = jwtConClaims(['type' => 'refresh', 'sub' => (string) $user->id]);
    expect((new TokenService($ok))->validateRefreshToken('a.b.c')['user']->id)->toBe($user->id);

    $sinSub = jwtConClaims(['type' => 'refresh', 'tv' => 0]);
    expect(fn () => (new TokenService($sinSub))->validateRefreshToken('a.b.c'))
        ->toThrow(TokenInvalidException::class, 'Usuario no encontrado en el token');
});

test('un refresh de usuario en lista negra se registra en security con su id', function () {
    $user = User::factory()->create();
    $refresh = tokens()->generateRefreshToken($user);
    Blacklist::create(['email' => strtoupper($user->email), 'reason' => 'abuso']);
    $logs = logsDeTokens();

    expect(fn () => tokens()->validateRefreshToken($refresh))->toThrow(TokenInvalidException::class, 'Sesión no permitida');

    $seguridad = collect($logs)->first(fn (array $l) => $l[0] === 'security');
    expect($seguridad[1])->toBe('warning')->and($seguridad[3])->toMatchArray(['user_id' => $user->id]);
});

test('revokeRefreshToken con un refresh sin sub no revoca', function () {
    $user = User::factory()->create();
    $jwt = jwtConClaims(['type' => 'refresh']);
    $jwt->shouldReceive('unsetToken')->once();
    $jwt->shouldNotReceive('invalidate');

    expect((new TokenService($jwt))->revokeRefreshToken('a.b.c', $user))->toBeFalse();
});

test('refreshToken registra la renovación con el usuario y los fallos generales como error', function () {
    $user = User::factory()->create();
    $logs = logsDeTokens();
    tokens()->refreshToken(tokens()->generateRefreshToken($user));

    $renovado = collect($logs)->first(fn (array $l) => $l[2] === 'Token renovado con rotación');
    expect($renovado[1])->toBe('info')->and($renovado[3])->toMatchArray(['user_id' => $user->id]);

    $jwt = Mockery::mock(JWTAuth::class);
    $servicio = Mockery::mock(TokenService::class, [$jwt])->makePartial();
    $servicio->shouldReceive('validateRefreshToken')->andReturn(['claims' => [], 'user' => $user]);
    $jwt->shouldReceive('setToken')->andThrow(new JWTException('falla'));
    $logs->exchangeArray([]);

    expect(fn () => $servicio->refreshToken('a.b.c'))->toThrow(JWTException::class, 'falla');
    expect($logs)->toHaveCount(1)
        ->and($logs[0][1])->toBe('error')->and($logs[0][2])->toBe('Error al renovar token')
        ->and($logs[0][3])->toMatchArray(['error' => 'falla']);
});

test('sin jwt.ttl ni jwt.refresh_ttl configurados se usan 60 y 20160 minutos', function () {
    config(['jwt' => array_diff_key((array) config('jwt'), ['ttl' => 1, 'refresh_ttl' => 1])]);
    $user = User::factory()->create();
    $this->travelTo(now()->startOfSecond());

    expect(tokens()->getTokenExpiration())->toBe(3600)
        ->and(tokens()->getRefreshTokenExpiration())->toBe(1209600)
        ->and(claimsDe(tokens()->generateRefreshToken($user))['exp'])->toBe(now()->addMinutes(20160)->timestamp);
});
