<?php

namespace App\Support\Social;

use App\Contracts\SocialIdentityResolverInterface;
use App\Exceptions\SocialLoginException;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Resolutor real: según el `type` del proveedor valida un id_token OIDC o cambia un access_token por el perfil con
 * Laravel Socialite. Cualquier fallo se reduce a `social_login_invalid` (el detalle va solo al log de la app).
 */
final class ProviderIdentityResolver implements SocialIdentityResolverInterface
{
    public function __construct(private readonly OidcIdTokenVerifier $oidc, private readonly Socialite $socialite) {}

    public function resolve(string $provider, array $credentials): SocialIdentity
    {
        /** @var array<string, mixed>|null $config */
        $config = config('social-login.providers.'.$provider);

        if (! is_array($config)) {
            throw SocialLoginException::unavailable();
        }

        return match ($config['type'] ?? null) {
            'oidc' => $this->fromIdToken($provider, $config, $credentials),
            'socialite' => $this->fromAccessToken($provider, $config, $credentials),
            default => throw SocialLoginException::unavailable(),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{id_token?: string|null, access_token?: string|null, nonce?: string|null}  $credentials
     */
    private function fromIdToken(string $provider, array $config, array $credentials): SocialIdentity
    {
        $token = $credentials['id_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialLoginException::invalid();
        }

        return $this->oidc->verify($provider, $config, $token, $credentials['nonce'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{id_token?: string|null, access_token?: string|null, nonce?: string|null}  $credentials
     */
    private function fromAccessToken(string $provider, array $config, array $credentials): SocialIdentity
    {
        $token = $credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialLoginException::invalid();
        }

        try {
            $this->assertTokenIsOurs($config, $token);

            /** @var class-string<AbstractProvider> $class */
            $class = $config['socialite_class'];
            /** @var SocialiteManager $manager */
            $manager = $this->socialite;
            $driver = $manager->buildProvider($class, [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect' => $config['redirect'] ?? 'https://invalid.local/unused',
            ]);
            $user = $driver->stateless()->userFromToken($token);
        } catch (SocialLoginException $e) {
            throw $e;
        } catch (Throwable) {
            throw SocialLoginException::invalid();
        }

        $id = (string) $user->getId();

        if ($id === '') {
            throw SocialLoginException::invalid();
        }

        $rawEmail = $user->getEmail();
        $email = is_string($rawEmail) && $rawEmail !== '' ? mb_strtolower(trim($rawEmail)) : null;
        $raw = $user->user;

        return new SocialIdentity(
            provider: $provider,
            providerUserId: $id,
            email: $email,
            emailVerified: $email !== null && (($config['trust_email'] ?? false) === true || ($raw['email_verified'] ?? false) === true),
            name: is_string($user->getName()) ? $user->getName() : null,
        );
    }

    /**
     * Un access_token de OTRA aplicación del mismo proveedor también devuelve un perfil válido: sin esta comprobación
     * cualquier app que obtenga un token de la víctima podría entrar aquí. El proveedor confirma que el token es de la nuestra.
     *
     * @param  array<string, mixed>  $config
     */
    private function assertTokenIsOurs(array $config, string $token): void
    {
        $url = $config['check_token_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        $response = Http::timeout(5)
            ->withBasicAuth((string) $config['client_id'], (string) $config['client_secret'])
            ->acceptJson()
            ->post(str_replace('{client_id}', rawurlencode((string) $config['client_id']), $url), ['access_token' => $token]);

        if (! $response->successful()) {
            throw SocialLoginException::invalid();
        }
    }
}
