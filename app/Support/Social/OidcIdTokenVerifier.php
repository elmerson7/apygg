<?php

namespace App\Support\Social;

use App\Exceptions\SocialLoginException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Valida un `id_token` OIDC: firma RS256 contra el JWKS del proveedor (la clave se elige por `kid`; un token con
 * otro algoritmo o `none` se rechaza), `iss`, `aud`, `exp`, `nonce` y que no se haya canjeado ya.
 * Sin estado entre peticiones (Octane): el JWKS va en la caché.
 */
final class OidcIdTokenVerifier
{
    /**
     * @param  array<string, mixed>  $config  entrada de `social-login.providers.<x>` (client_ids, issuers, jwks_uri)
     *
     * @throws SocialLoginException
     */
    public function verify(string $provider, array $config, string $idToken, ?string $nonce): SocialIdentity
    {
        $leeway = max(0, (int) config('social-login.leeway_seconds', 60));

        try {
            $claims = (array) $this->decode($provider, (string) ($config['jwks_uri'] ?? ''), $idToken, $leeway);
        } catch (Throwable) {
            throw SocialLoginException::invalid();
        }

        $this->assertClaims($claims, $config, $nonce);

        // Un id_token se canjea una sola vez (hasta su caducidad): un token robado de un log no sirve de nuevo.
        $ttl = max(1, (int) $claims['exp'] - time() + $leeway);

        if (! Cache::add('social-login:idtoken:'.hash('sha256', $idToken), 1, $ttl)) {
            throw SocialLoginException::invalid();
        }

        $email = isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== '' ? mb_strtolower(trim($claims['email'])) : null;

        return new SocialIdentity(
            provider: $provider,
            providerUserId: (string) $claims['sub'],
            email: $email,
            emailVerified: $email !== null && in_array($claims['email_verified'] ?? false, [true, 'true'], true),
            name: isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $config
     *
     * @throws SocialLoginException
     */
    private function assertClaims(array $claims, array $config, ?string $nonce): void
    {
        $audiences = array_values(array_filter((array) ($claims['aud'] ?? []), 'is_string'));
        $clientIds = array_values(array_filter((array) ($config['client_ids'] ?? []), 'is_string'));
        $issuers = array_values(array_filter((array) ($config['issuers'] ?? []), 'is_string'));

        $valido = isset($claims['exp'], $claims['sub'])
            && is_string($claims['sub']) && $claims['sub'] !== ''
            && isset($claims['iss']) && in_array($claims['iss'], $issuers, true)
            && array_intersect($audiences, $clientIds) !== [];

        // El nonce ata el token a la petición de la app: si cualquiera de los dos lo trae, deben coincidir.
        $claimNonce = $claims['nonce'] ?? null;

        if ($nonce !== null || $claimNonce !== null || config('social-login.require_nonce', true)) {
            $valido = $valido && is_string($nonce) && $nonce !== '' && is_string($claimNonce) && hash_equals($claimNonce, $nonce);
        }

        if (! $valido) {
            throw SocialLoginException::invalid();
        }
    }

    private function decode(string $provider, string $jwksUri, string $idToken, int $leeway): object
    {
        JWT::$leeway = $leeway; // constante de config: no cambia entre peticiones

        try {
            return JWT::decode($idToken, $this->keys($provider, $jwksUri, false));
        } catch (\UnexpectedValueException $e) {
            // `kid` desconocido: el proveedor pudo rotar claves. Se vuelve a pedir el JWKS (como mucho una vez por minuto).
            if (! str_contains($e->getMessage(), '"kid"') || ! Cache::add('social-login:jwks-refetch:'.$provider, 1, 60)) {
                throw $e;
            }

            return JWT::decode($idToken, $this->keys($provider, $jwksUri, true));
        }
    }

    /**
     * @return array<string, Key>
     */
    private function keys(string $provider, string $jwksUri, bool $refresh): array
    {
        $cacheKey = 'social-login:jwks:'.$provider;

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember($cacheKey, now()->addMinutes(max(1, (int) config('social-login.jwks_cache_minutes', 60))), function () use ($jwksUri): array {
            $response = Http::timeout(5)->acceptJson()->get($jwksUri);

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new \RuntimeException('JWKS no disponible');
            }

            return $response->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
