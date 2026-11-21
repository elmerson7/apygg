<?php

namespace App\Services\Otp;

use App\Contracts\AuthServiceInterface;
use App\Contracts\OtpLoginServiceInterface;
use App\Exceptions\OtpLoginException;
use App\Jobs\SendSmsJob;
use App\Models\Blacklist;
use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\LogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login por código de un solo uso (docs/otp-login.md).
 *
 * - El código (solo dígitos, `random_int`) se guarda hasheado, caduca en `ttl_minutes`, admite `max_attempts`
 *   intentos y se consume una sola vez bajo bloqueo de fila. Pedir uno nuevo anula los anteriores del destino.
 * - Nada revela si el destino tiene cuenta: los límites (por destino y por IP) cuentan igual, las respuestas son
 *   idénticas y, sin cuenta, se hace el mismo trabajo de hash que con cuenta.
 * - Entrar usa `AuthServiceInterface::authenticateVerified`: lista negra, 2FA y sesión por dispositivo como el login normal.
 */
class OtpLoginService implements OtpLoginServiceInterface
{
    public function __construct(private readonly AuthServiceInterface $auth) {}

    public function request(string $channel, string $destination, ?string $ipAddress = null): int
    {
        $this->assertAvailable($channel);

        $destination = $this->normalize($channel, $destination);
        $hash = $this->destinationHash($channel, $destination);
        $window = max(1, (int) config('otp-login.send_window_minutes', 15)) * 60;

        $this->hitOrFail('otp:send:dest:'.$hash, (int) config('otp-login.send_per_destination', 3), $window);
        $this->hitOrFail('otp:send:ip:'.($ipAddress ?? 'unknown'), (int) config('otp-login.send_per_ip', 10), $window);

        $ttl = max(1, (int) config('otp-login.ttl_minutes', 10));
        $user = $this->findUser($channel, $destination);

        if ($user === null || $this->isBlocked($user)) {
            Hash::make(Str::random(16)); // mismo coste que con cuenta: el tiempo no delata si existe

            return $ttl * 60;
        }

        $code = $this->newCode();

        DB::transaction(function () use ($user, $channel, $hash, $code, $ttl, $ipAddress): void {
            // Un solo código vigente por destino: el nuevo anula los anteriores.
            LoginOtp::query()
                ->where('channel', $channel)
                ->where('destination_hash', $hash)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            LoginOtp::query()->create([
                'user_id' => $user->id,
                'channel' => $channel,
                'destination_hash' => $hash,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($ttl),
                'ip' => $ipAddress,
            ]);
        });

        $this->send($channel, $user, $destination, $code, $ttl);

        LogService::info('Código de login emitido', ['user_id' => $user->id, 'channel' => $channel, 'ip' => $ipAddress], 'security');

        return $ttl * 60;
    }

    public function verify(string $channel, string $destination, string $code, ?string $ipAddress = null): array
    {
        $this->assertAvailable($channel);

        $destination = $this->normalize($channel, $destination);
        $hash = $this->destinationHash($channel, $destination);
        $window = max(1, (int) config('otp-login.verify_window_minutes', 15)) * 60;
        $destKey = 'otp:fail:dest:'.$hash;
        $ipKey = 'otp:fail:ip:'.($ipAddress ?? 'unknown');

        foreach ([[$destKey, 'verify_failures_per_destination', 10], [$ipKey, 'verify_failures_per_ip', 20]] as [$key, $option, $default]) {
            if (RateLimiter::tooManyAttempts($key, max(1, (int) config('otp-login.'.$option, $default)))) {
                throw OtpLoginException::rateLimited(RateLimiter::availableIn($key));
            }
        }

        $user = $this->findUser($channel, $destination);

        if ($user === null) {
            Hash::check($code, Hash::make(Str::random(16))); // mismo trabajo que con cuenta
            $this->fail($destKey, $ipKey, $window);
        }

        $ok = $this->consume($user, $channel, $hash, $code);

        if (! $ok) {
            LogService::warning('Código de login rechazado', ['user_id' => $user->id, 'channel' => $channel, 'ip' => $ipAddress], 'security');
            $this->fail($destKey, $ipKey, $window);
        }

        RateLimiter::clear($destKey);

        // Recibir el código en el buzón demuestra que el correo es suyo.
        if ($channel === self::CHANNEL_MAIL && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->auth->authenticateVerified($user, 'otp:'.$channel, $ipAddress);
    }

    /**
     * Comprueba y consume el código. El intento se cuenta aunque falle (por eso el resultado sale de la transacción y no
     * se lanza dentro: se desharía el contador).
     */
    private function consume(User $user, string $channel, string $hash, string $code): bool
    {
        return DB::transaction(function () use ($user, $channel, $hash, $code): bool {
            $otp = LoginOtp::query()
                ->where('user_id', $user->id)
                ->where('channel', $channel)
                ->where('destination_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                Hash::check($code, Hash::make(Str::random(16)));

                return false;
            }

            $max = max(1, (int) config('otp-login.max_attempts', 5));

            if ($otp->attempts >= $max) {
                $otp->forceFill(['consumed_at' => now()])->save();

                return false;
            }

            $otp->attempts++;

            if (! Hash::check($code, $otp->code_hash)) {
                if ($otp->attempts >= $max) {
                    $otp->consumed_at = now(); // agotado: ya no sirve ni el código bueno
                }

                $otp->save();

                return false;
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    /**
     * @return never
     */
    private function fail(string $destKey, string $ipKey, int $window): void
    {
        RateLimiter::hit($destKey, $window);
        RateLimiter::hit($ipKey, $window);

        throw OtpLoginException::invalid();
    }

    private function hitOrFail(string $key, int $max, int $window): void
    {
        if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
            throw OtpLoginException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $window);
    }

    private function assertAvailable(string $channel): void
    {
        if (! config('otp-login.enabled', false)) {
            throw OtpLoginException::unavailable();
        }

        if ($channel === self::CHANNEL_SMS && ! config('otp-login.sms_enabled', false)) {
            throw OtpLoginException::unavailable();
        }

        if (! in_array($channel, [self::CHANNEL_MAIL, self::CHANNEL_SMS], true)) {
            throw OtpLoginException::unavailable();
        }
    }

    private function normalize(string $channel, string $destination): string
    {
        $destination = trim($destination);

        return $channel === self::CHANNEL_SMS
            ? (preg_replace('/[\s().-]+/', '', $destination) ?? $destination)
            : mb_strtolower($destination);
    }

    private function destinationHash(string $channel, string $destination): string
    {
        return hash_hmac('sha256', $channel.'|'.$destination, (string) config('app.key'));
    }

    /**
     * Por teléfono solo si identifica a UNA persona (un número compartido no da acceso a ninguna).
     */
    private function findUser(string $channel, string $destination): ?User
    {
        if ($channel === self::CHANNEL_MAIL) {
            return User::query()->whereRaw('lower(email) = ?', [$destination])->first();
        }

        $matches = User::query()
            ->whereHas('profile', fn ($q) => $q->whereRaw("regexp_replace(phone, '[\\s().-]+', '', 'g') = ?", [$destination]))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function isBlocked(User $user): bool
    {
        return Blacklist::isBlocked(email: $user->email, identityDocument: $user->identity_document, userId: $user->id);
    }

    private function newCode(): string
    {
        $length = max(6, (int) config('otp-login.length', 6));
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    private function send(string $channel, User $user, string $destination, string $code, int $ttl): void
    {
        if ($channel === self::CHANNEL_SMS) {
            SendSmsJob::dispatch($destination, __('otp-login.sms', ['code' => $code, 'minutes' => $ttl]), bin2hex(random_bytes(16)));

            return;
        }

        $user->notify(new LoginCodeNotification($code, $ttl));
    }
}
