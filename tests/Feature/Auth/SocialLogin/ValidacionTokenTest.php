<?php

use App\Exceptions\SocialLoginException;
use App\Support\Social\OidcIdTokenVerifier;
use App\Support\Social\ProviderIdentityResolver;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

const JWKS_URI = 'https://idp.test/jwks';

beforeEach(function () {
    Cache::flush();
    config([
        'social-login.enabled' => true,
        'social-login.providers.google.client_ids' => ['cliente-google'],
        'social-login.providers.google.jwks_uri' => JWKS_URI,
        'social-login.require_nonce' => false,
    ]);
});

/** @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>} */
function parClaves(string $kid): array
{
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($private);
    $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

    return ['private' => $private, 'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]];
}

function firmar(array $claims, $private, string $kid, string $alg = 'RS256'): string
{
    $base = ['iss' => 'https://accounts.google.com', 'aud' => 'cliente-google', 'sub' => 'sub-1', 'exp' => time() + 600, 'iat' => time(),
        'email' => 'ana@example.com', 'email_verified' => true];

    return JWT::encode(array_merge($base, $claims), $private, $alg, $kid);
}

function verificar(string $token, ?string $nonce = null)
{
    return app(OidcIdTokenVerifier::class)->verify('google', config('social-login.providers.google'), $token, $nonce);
}

function conJwks(array ...$jwks): void
{
    Http::fake([JWKS_URI => Http::response(['keys' => $jwks])]);
}

test('acepta un id_token bien firmado y devuelve la identidad', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);

    $id = verificar(firmar([], $k['private'], 'k1'));

    expect($id->providerUserId)->toBe('sub-1')->and($id->email)->toBe('ana@example.com')->and($id->emailVerified)->toBeTrue();
});

test('email_verified como cadena "true" (Google) cuenta como verificado y "false" no', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);

    expect(verificar(firmar(['email_verified' => 'true', 'sub' => 'a'], $k['private'], 'k1'))->emailVerified)->toBeTrue()
        ->and(verificar(firmar(['email_verified' => 'false', 'sub' => 'b'], $k['private'], 'k1'))->emailVerified)->toBeFalse()
        ->and(verificar(firmar(['email_verified' => 1, 'sub' => 'c'], $k['private'], 'k1'))->emailVerified)->toBeFalse();
});

test('rechaza audiencia, emisor, caducidad y firma incorrectos', function (array $claims, bool $otraClave) {
    $k = parClaves('k1');
    $otra = parClaves('k1');
    conJwks($k['jwk']);

    verificar(firmar($claims, $otraClave ? $otra['private'] : $k['private'], 'k1'));
})->with([
    'audiencia de otra app' => [['aud' => 'otra-app'], false],
    'emisor distinto' => [['iss' => 'https://evil.test'], false],
    'caducado' => [['exp' => time() - 3600], false],
    'firmado con otra clave' => [[], true],
    'sin sub' => [['sub' => ''], false],
])->throws(SocialLoginException::class);

test('rechaza un token firmado con HS256 usando la clave publica como secreto (confusion de algoritmo)', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);
    $token = JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => 'cliente-google', 'sub' => 'x', 'exp' => time() + 600], str_repeat('a', 40), 'HS256', 'k1');

    verificar($token);
})->throws(SocialLoginException::class);

test('rechaza un token sin firma (alg none)', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);
    $b64 = fn (array $v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
    $token = $b64(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'k1']).'.'.$b64(['iss' => 'https://accounts.google.com', 'aud' => 'cliente-google', 'sub' => 'x', 'exp' => time() + 600]).'.';

    verificar($token);
})->throws(SocialLoginException::class);

test('el nonce debe coincidir: distinto, ausente en el token o ausente en la peticion', function (?string $enToken, ?string $enPeticion) {
    $k = parClaves('k1');
    conJwks($k['jwk']);

    verificar(firmar($enToken === null ? [] : ['nonce' => $enToken], $k['private'], 'k1'), $enPeticion);
})->with([
    'distinto' => ['abc', 'xyz'],
    'peticion lo envia y el token no' => [null, 'abc'],
    'token lo trae y la peticion no' => ['abc', null],
])->throws(SocialLoginException::class);

test('con el nonce correcto pasa y con require_nonce se exige', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);

    expect(verificar(firmar(['nonce' => 'n-1'], $k['private'], 'k1'), 'n-1')->providerUserId)->toBe('sub-1');

    config(['social-login.require_nonce' => true]);
    expect(fn () => verificar(firmar(['sub' => 'otro'], $k['private'], 'k1')))->toThrow(SocialLoginException::class);
});

test('por defecto el nonce es obligatorio: sin él el id_token se rechaza', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);
    expect(require base_path('config/social-login.php'))->toHaveKey('require_nonce', true);

    config(['social-login.require_nonce' => (require base_path('config/social-login.php'))['require_nonce']]);
    expect(fn () => verificar(firmar([], $k['private'], 'k1')))->toThrow(SocialLoginException::class);
    expect(verificar(firmar(['nonce' => 'n-2'], $k['private'], 'k1'), 'n-2')->providerUserId)->toBe('sub-1');
});

test('un id_token solo se canjea una vez', function () {
    $k = parClaves('k1');
    conJwks($k['jwk']);
    $token = firmar([], $k['private'], 'k1');

    verificar($token);

    expect(fn () => verificar($token))->toThrow(SocialLoginException::class);
});

test('si el proveedor rota claves (kid nuevo) vuelve a pedir el JWKS una vez', function () {
    $viejo = parClaves('k-old');
    $nuevo = parClaves('k-new');
    Http::fake([JWKS_URI => Http::sequence()->push(['keys' => [$viejo['jwk']]])->push(['keys' => [$viejo['jwk'], $nuevo['jwk']]])]);

    verificar(firmar(['sub' => 'a'], $viejo['private'], 'k-old'));
    expect(verificar(firmar(['sub' => 'b'], $nuevo['private'], 'k-new'))->providerUserId)->toBe('b');
    Http::assertSentCount(2);
});

test('un kid inexistente no provoca peticiones al proveedor sin limite', function () {
    $k = parClaves('k1');
    $falsa = parClaves('inventada');
    conJwks($k['jwk']);

    foreach (['x1', 'x2', 'x3'] as $sub) {
        expect(fn () => verificar(firmar(['sub' => $sub], $falsa['private'], 'inventada')))->toThrow(SocialLoginException::class);
    }

    Http::assertSentCount(2); // la inicial + una sola recarga por minuto
});

test('si el JWKS no esta disponible rechaza con error generico', function () {
    $k = parClaves('k1');
    Http::fake([JWKS_URI => Http::response('boom', 500)]);

    verificar(firmar([], $k['private'], 'k1'));
})->throws(SocialLoginException::class);

// ── access_token (Socialite) ────────────────────────────────────────────────────────

function socialiteConUsuario(string $id, ?string $email, array $raw = []): void
{
    $user = (new SocialiteUser)->setRaw($raw)->map(['id' => $id, 'email' => $email, 'name' => 'Gh User']);
    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('userFromToken')->with('tok-gh')->andReturn($user);
    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('buildProvider')->andReturn($driver);
    app()->instance(SocialiteFactory::class, $factory);
}

function configGithub(): void
{
    config(['social-login.providers.github.client_id' => 'gh-id', 'social-login.providers.github.client_secret' => 'gh-secret']);
}

test('un access_token de GitHub de nuestra app devuelve el perfil con el email de confianza', function () {
    configGithub();
    Http::fake(['api.github.com/applications/gh-id/token' => Http::response(['id' => 1], 200)]);
    socialiteConUsuario('123', 'Dev@Example.com');

    $id = app(ProviderIdentityResolver::class)->resolve('github', ['access_token' => 'tok-gh']);

    expect($id->providerUserId)->toBe('123')->and($id->email)->toBe('dev@example.com')->and($id->emailVerified)->toBeTrue();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization') && $r['access_token'] === 'tok-gh');
});

test('un access_token de otra aplicacion de GitHub se rechaza antes de pedir el perfil', function () {
    configGithub();
    Http::fake(['api.github.com/applications/gh-id/token' => Http::response(['message' => 'Not Found'], 404)]);
    socialiteConUsuario('123', 'dev@example.com');

    app(ProviderIdentityResolver::class)->resolve('github', ['access_token' => 'tok-gh']);
})->throws(SocialLoginException::class);

test('un proveedor de Socialite sin trust_email no da el email por verificado', function () {
    configGithub();
    config(['social-login.providers.github.trust_email' => false]);
    Http::fake(['api.github.com/*' => Http::response([], 200)]);
    socialiteConUsuario('123', 'dev@example.com');

    expect(app(ProviderIdentityResolver::class)->resolve('github', ['access_token' => 'tok-gh'])->emailVerified)->toBeFalse();
});

test('sin token del tipo que el proveedor espera se rechaza', function () {
    configGithub();

    expect(fn () => app(ProviderIdentityResolver::class)->resolve('github', ['id_token' => 'x']))->toThrow(SocialLoginException::class)
        ->and(fn () => app(ProviderIdentityResolver::class)->resolve('google', ['access_token' => 'x']))->toThrow(SocialLoginException::class);
});
