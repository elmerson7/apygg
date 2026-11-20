<?php

namespace App\Services;

use App\Contracts\EmailVerificationServiceInterface;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Token sin estado en BD: `{id}.{caducidad}.{hmac}` donde el HMAC cubre id, correo y caducidad
 * con la clave de la app. Cambiar el correo o pasar la caducidad lo invalida; verificar dos
 * veces es inofensivo (idempotente).
 */
class EmailVerificationService implements EmailVerificationServiceInterface
{
    public function send(User $user): void
    {
        $user->notify(new VerifyEmailNotification($this->url($user), $this->expireMinutes()));
    }

    public function sendThrottled(User $user): bool
    {
        $enviado = RateLimiter::attempt(
            $this->limiterKey($user),
            max(1, (int) config('auth.email_verification.resend_per_hour', 3)),
            function () use ($user): void {
                $this->send($user);
            },
            3600
        );

        return $enviado !== false;
    }

    public function retryAfter(User $user): int
    {
        return RateLimiter::availableIn($this->limiterKey($user));
    }

    public function verify(string $token): User
    {
        $invalido = new ApiException('Enlace de verificación inválido o caducado', 422, [], 'token_invalid');

        if (preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m) !== 1) {
            throw $invalido;
        }

        [, $id, $expira, $firma] = $m;

        if ((int) $expira < now()->timestamp) {
            throw $invalido;
        }

        $user = User::query()->find((int) $id);

        if (! $user instanceof User || ! hash_equals($this->sign($user, (int) $expira), $firma)) {
            throw $invalido;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();

            LogService::info('Correo verificado', ['user_id' => $user->id], 'security');
        }

        return $user;
    }

    public function makeToken(User $user): string
    {
        $expira = now()->addMinutes($this->expireMinutes())->timestamp;

        return $user->id.'.'.$expira.'.'.$this->sign($user, $expira);
    }

    private function sign(User $user, int $expira): string
    {
        $clave = hash_hmac('sha256', 'email-verification', (string) config('app.key'));

        return hash_hmac('sha256', $user->id.'|'.mb_strtolower($user->email).'|'.$expira, $clave);
    }

    private function url(User $user): string
    {
        $base = (string) (config('auth.email_verification.url') ?: url('/verify-email'));
        $query = http_build_query(['token' => $this->makeToken($user)]);

        $fragment = '';
        if (($pos = strpos($base, '#')) !== false) {
            $fragment = substr($base, $pos);
            $base = substr($base, 0, $pos);
        }

        return $base.(str_contains($base, '?') ? '&' : '?').$query.$fragment;
    }

    private function expireMinutes(): int
    {
        return max(1, (int) config('auth.email_verification.expire_minutes', 1440));
    }

    private function limiterKey(User $user): string
    {
        return 'email-verification:'.$user->id;
    }
}
