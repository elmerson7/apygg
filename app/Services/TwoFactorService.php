<?php

namespace App\Services;

use App\Contracts\TwoFactorServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\LoginLockedException;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP de la API.
 *
 * Reutiliza las columnas cifradas del back-office (`app_authentication_secret` y
 * `app_authentication_recovery_codes`): una persona tiene UN autenticador para ambos.
 * Los códigos de recuperación se guardan hasheados (mismo formato que Filament) y se
 * consumen con bloqueo de fila. Sin estado entre peticiones (Octane): todo en BD y caché.
 */
class TwoFactorService implements TwoFactorServiceInterface
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function isEnabled(User $user): bool
    {
        return (bool) config('auth.two_factor.enabled', true)
            && $user->getAppAuthenticationSecret() !== null
            && $user->getAppAuthenticationSecret() !== '';
    }

    public function status(User $user): array
    {
        return [
            'enabled' => $this->isEnabled($user),
            'recovery_codes_remaining' => $this->isEnabled($user) ? count($user->getAppAuthenticationRecoveryCodes() ?? []) : 0,
        ];
    }

    public function beginSetup(User $user): array
    {
        $this->ensureAvailable();

        if ($this->isEnabled($user)) {
            throw BusinessLogicException::invalidState('El 2FA ya está activo', 'enabled', 'disabled');
        }

        $secret = $this->google2fa->generateSecretKey(32);

        Cache::put($this->pendingKey($user), Crypt::encryptString($secret), now()->addMinutes(10));

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl($this->issuer(), $user->email, $secret),
        ];
    }

    public function confirmSetup(User $user, string $code): array
    {
        $this->ensureAvailable();

        $cifrado = Cache::get($this->pendingKey($user));
        $secret = is_string($cifrado) ? $this->decrypt($cifrado) : null;

        if ($secret === null) {
            throw BusinessLogicException::invalidState('No hay una activación de 2FA en curso: inicia con /user/2fa/setup', 'none', 'pending');
        }

        $this->guardAttempts($user);

        if (! $this->validTotp($user, $secret, $code)) {
            $this->failAttempt($user);

            throw new TwoFactorException;
        }

        $this->clearAttempts($user);

        $plain = $this->newRecoveryCodes();

        $user->forceFill([
            'app_authentication_secret' => $secret,
            'app_authentication_recovery_codes' => $this->hashCodes($plain),
        ])->save();

        Cache::forget($this->pendingKey($user));

        LogService::info('2FA activado', ['user_id' => $user->id], 'security');

        return $plain;
    }

    public function disable(User $user, string $password, string $code): void
    {
        $this->assertReauth($user, $password, $code);

        $user->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        LogService::info('2FA desactivado', ['user_id' => $user->id], 'security');
    }

    public function regenerateRecoveryCodes(User $user, string $password, string $code): array
    {
        $this->assertReauth($user, $password, $code);

        $plain = $this->newRecoveryCodes();

        $user->forceFill(['app_authentication_recovery_codes' => $this->hashCodes($plain)])->save();

        LogService::info('Códigos de recuperación 2FA renovados', ['user_id' => $user->id], 'security');

        return $plain;
    }

    public function startChallenge(User $user): array
    {
        $ttl = max(1, (int) config('auth.two_factor.challenge_ttl_minutes', 5));
        $token = Str::random(64);

        Cache::put($this->challengeKey($token), ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes($ttl));

        return ['challenge_token' => $token, 'expires_in' => $ttl * 60];
    }

    public function resolveChallenge(string $challengeToken, string $code): User
    {
        $key = $this->challengeKey($challengeToken);
        $challenge = Cache::get($key);

        if (! is_array($challenge) || ! isset($challenge['user_id'])) {
            throw new TwoFactorException;
        }

        $user = User::query()->find((int) $challenge['user_id']);

        if (! $user instanceof User || ! $this->isEnabled($user)) {
            Cache::forget($key);

            throw new TwoFactorException;
        }

        $this->guardAttempts($user);

        if (! $this->verifyCode($user, $code)) {
            $this->failAttempt($user);

            // Un desafío admite pocos intentos: al agotarlos hay que volver a iniciar sesión.
            $intentos = (int) ($challenge['attempts'] ?? 0) + 1;
            if ($intentos >= (int) config('auth.two_factor.max_attempts', 5)) {
                Cache::forget($key);
            } else {
                Cache::put($key, ['user_id' => $user->id, 'attempts' => $intentos], now()->addMinutes((int) config('auth.two_factor.challenge_ttl_minutes', 5)));
            }

            throw new TwoFactorException;
        }

        // El desafío se consume de forma atómica: dos verificaciones simultáneas no abren dos sesiones.
        if (! Cache::add($key.':used', 1, now()->addMinutes(10))) {
            throw new TwoFactorException;
        }

        Cache::forget($key);
        $this->clearAttempts($user);

        return $user;
    }

    /**
     * Código válido: TOTP de 6 dígitos (una sola vez por ventana) o de recuperación (un solo uso).
     */
    private function verifyCode(User $user, string $code): bool
    {
        $code = trim($code);
        $secret = $user->getAppAuthenticationSecret();

        if ($secret === null || $code === '') {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->validTotp($user, $secret, $code);
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function validTotp(User $user, string $secret, string $code): bool
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        if (! $this->google2fa->verifyKey($secret, $code, (int) config('auth.two_factor.window', 1))) {
            return false;
        }

        // Anti-repetición: el mismo código no vale dos veces mientras dure su ventana.
        return Cache::add('auth:2fa:used:'.$user->id.':'.$code, 1, 120);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $bloqueado = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $bloqueado instanceof User) {
                return false;
            }

            $restantes = [];
            $encontrado = false;

            foreach ($bloqueado->getAppAuthenticationRecoveryCodes() ?? [] as $hash) {
                if (! $encontrado && Hash::check($code, $hash)) {
                    $encontrado = true;

                    continue;
                }

                $restantes[] = $hash;
            }

            if (! $encontrado) {
                return false;
            }

            $bloqueado->forceFill(['app_authentication_recovery_codes' => $restantes])->save();
            $user->forceFill(['app_authentication_recovery_codes' => $restantes])->syncOriginal();

            LogService::warning('Código de recuperación 2FA usado', [
                'user_id' => $user->id,
                'remaining' => count($restantes),
            ], 'security');

            return true;
        });
    }

    /**
     * Operación sensible: contraseña actual + código vigente, con el mismo límite de intentos.
     */
    private function assertReauth(User $user, string $password, string $code): void
    {
        if (! $this->isEnabled($user)) {
            throw BusinessLogicException::invalidState('El 2FA no está activo', 'disabled', 'enabled');
        }

        $this->guardAttempts($user);

        if (! Hash::check($password, $user->password) || ! $this->verifyCode($user, $code)) {
            $this->failAttempt($user);

            throw new TwoFactorException('Contraseña o código de verificación inválidos');
        }

        $this->clearAttempts($user);
    }

    private function guardAttempts(User $user): void
    {
        $max = (int) config('auth.two_factor.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($this->attemptsKey($user), $max)) {
            throw new LoginLockedException((int) config('auth.two_factor.lockout_minutes', 15));
        }
    }

    private function failAttempt(User $user): void
    {
        RateLimiter::hit($this->attemptsKey($user), (int) config('auth.two_factor.lockout_minutes', 15) * 60);

        LogService::warning('Código 2FA inválido', ['user_id' => $user->id], 'security');
    }

    private function clearAttempts(User $user): void
    {
        RateLimiter::clear($this->attemptsKey($user));
    }

    private function ensureAvailable(): void
    {
        if (! config('auth.two_factor.enabled', true)) {
            throw BusinessLogicException::operationNotAllowed('El 2FA no está habilitado en esta instalación', 'two_factor');
        }
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0, $n = max(1, (int) config('auth.two_factor.recovery_codes', 8)); $i < $n; $i++) {
            $codes[] = Str::random(10).'-'.Str::random(10);
        }

        return $codes;
    }

    /**
     * @param  list<string>  $plain
     * @return list<string>
     */
    private function hashCodes(array $plain): array
    {
        return array_map(fn (string $c): string => Hash::make($c), $plain);
    }

    private function issuer(): string
    {
        return (string) (config('auth.two_factor.issuer') ?: config('app.name'));
    }

    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pendingKey(User $user): string
    {
        return 'auth:2fa:pending:'.$user->id;
    }

    private function attemptsKey(User $user): string
    {
        return 'auth:2fa:attempts:'.$user->id;
    }

    private function challengeKey(string $token): string
    {
        return 'auth:2fa:challenge:'.hash('sha256', $token);
    }
}
