<?php

namespace App\Services\Social;

use App\Contracts\AuthServiceInterface;
use App\Contracts\SocialIdentityResolverInterface;
use App\Contracts\SocialLoginServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\SocialLoginException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\LogService;
use App\Support\Social\SocialIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login social: identidad verificada del proveedor -> usuario de la API -> sesión (misma puerta que el login normal,
 * así respeta 2FA, lista negra y sesiones por dispositivo).
 *
 * Reglas de vinculación (docs/social-login.md):
 *  1. Si el `provider_user_id` ya está vinculado, manda esa vinculación (el email puede haber cambiado).
 *  2. Si no, solo se vincula por email cuando el PROVEEDOR lo da por verificado. Un email sin verificar nunca toma una cuenta.
 *  3. Si la cuenta local nunca verificó su correo, al vincular se le cambia la contraseña por una aleatoria y se cierran
 *     sus sesiones (quien la registró antes con ese email, sin ser su dueño, no conserva acceso).
 *  4. Sin cuenta: se crea solo con `social-login.auto_register` y email verificado.
 */
class SocialLoginService implements SocialLoginServiceInterface
{
    public function __construct(
        private readonly SocialIdentityResolverInterface $resolver,
        private readonly AuthServiceInterface $auth,
        private readonly UserServiceInterface $users,
    ) {}

    public function isAvailable(string $provider): bool
    {
        if (! config('social-login.enabled', false)) {
            return false;
        }

        /** @var array<string, mixed>|null $config */
        $config = config('social-login.providers.'.$provider);

        if (! is_array($config)) {
            return false;
        }

        return match ($config['type'] ?? null) {
            'oidc' => ! empty($config['client_ids']),
            'socialite' => ! empty($config['client_id']) && ! empty($config['client_secret']) && ! empty($config['socialite_class']),
            default => false,
        };
    }

    public function exchange(string $provider, array $credentials, ?string $ipAddress = null): array
    {
        if (! $this->isAvailable($provider)) {
            throw SocialLoginException::unavailable();
        }

        $limiterKey = 'social-login:fail:'.($ipAddress ?? 'unknown');
        $max = max(1, (int) config('social-login.max_failures_per_ip', 10));

        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            throw SocialLoginException::rateLimited(RateLimiter::availableIn($limiterKey));
        }

        try {
            $identity = $this->resolver->resolve($provider, $credentials);
        } catch (SocialLoginException $e) {
            RateLimiter::hit($limiterKey, max(1, (int) config('social-login.failure_window_minutes', 15)) * 60);
            LogService::warning('Login social rechazado', ['provider' => $provider, 'ip' => $ipAddress, 'code' => $e->getType()], 'security');

            throw $e;
        }

        $user = $this->userFor($identity);

        return $this->auth->authenticateVerified($user, 'social:'.$provider, $ipAddress);
    }

    /**
     * Dos primeros logins simultáneos de la misma persona chocan en los únicos de BD: el perdedor repite y ya encuentra lo del ganador.
     */
    private function userFor(SocialIdentity $identity): User
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->resolveUser($identity));
            } catch (UniqueConstraintViolationException|BusinessLogicException) {
                if ($attempt >= 2) {
                    throw SocialLoginException::accountConflict();
                }
            }
        }
    }

    private function resolveUser(SocialIdentity $identity): User
    {
        $email = $identity->email !== null ? mb_strtolower(trim($identity->email)) : null;

        $account = SocialAccount::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->lockForUpdate()
            ->first();

        if ($account !== null) {
            $user = User::query()->find($account->user_id);

            if ($user === null) {
                throw SocialLoginException::notRegistered();
            }

            $account->forceFill([
                'email' => $email ?? $account->email,
                'email_verified' => $identity->emailVerified,
                'last_login_at' => now(),
            ])->save();

            return $user;
        }

        // Sin vinculación previa solo cuenta un email que el proveedor da por verificado.
        if ($email === null || $email === '' || ! $identity->emailVerified) {
            throw SocialLoginException::emailUnverified();
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null && User::onlyTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw SocialLoginException::notRegistered();
        }

        if ($user !== null) {
            return $this->link($user, $identity);
        }

        if (! config('social-login.auto_register', false)) {
            throw SocialLoginException::notRegistered();
        }

        $user = $this->users->create([
            'name' => $identity->name !== null && trim($identity->name) !== '' ? trim($identity->name) : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(48), // sin contraseña usable: entra por el proveedor o restablece por correo
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->createAccount($user, $identity);

        LogService::info('Usuario creado por login social', ['user_id' => $user->id, 'provider' => $identity->provider], 'security');

        return $user;
    }

    private function link(User $user, SocialIdentity $identity): User
    {
        if (SocialAccount::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists()) {
            throw SocialLoginException::accountConflict();
        }

        if ($user->email_verified_at === null) {
            // Defensa contra el «pre-secuestro»: alguien pudo registrar este email sin demostrar que es suyo.
            $user->forceFill([
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
            ])->save();
            $user->revokeAllTokens();

            LogService::warning('Login social vinculó una cuenta con correo sin verificar: contraseña invalidada', [
                'user_id' => $user->id,
                'provider' => $identity->provider,
            ], 'security');
        }

        $this->createAccount($user, $identity);

        LogService::info('Identidad social vinculada', ['user_id' => $user->id, 'provider' => $identity->provider], 'security');

        return $user;
    }

    private function createAccount(User $user, SocialIdentity $identity): void
    {
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->providerUserId,
            'email' => $identity->email !== null ? mb_strtolower(trim($identity->email)) : null,
            'email_verified' => $identity->emailVerified,
            'last_login_at' => now(),
        ]);
    }
}
