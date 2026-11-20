<?php

namespace App\Services;

use App\Contracts\SessionServiceInterface;
use App\Models\User;
use App\Models\UserSession;
use App\Support\DeviceLabel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesiones de usuario. Sin estado en el servicio (Octane): todo vive en BD y en caché.
 */
class SessionService implements SessionServiceInterface
{
    public function start(User $user, ?string $ip, ?string $userAgent): UserSession
    {
        return UserSession::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device' => DeviceLabel::from($userAgent),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'ip' => $ip,
            'refresh_jti' => $this->newRefreshJti(),
            'last_used_at' => now(),
        ]);
    }

    public function newRefreshJti(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function listFor(User $user, ?string $currentSid): array
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (UserSession $s): array => [
                'id' => $s->public_id,
                'device' => $s->device,
                'ip' => $s->ip,
                'created_at' => $s->created_at->toIso8601String(),
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'current' => $currentSid !== null && hash_equals($s->public_id, $currentSid),
            ])
            ->values()
            ->all();
    }

    public function revoke(User $user, string $publicId, string $reason = 'user'): void
    {
        $afectadas = UserSession::query()
            ->where('user_id', $user->id)
            ->where('public_id', $publicId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        if ($afectadas === 0) {
            throw (new ModelNotFoundException)->setModel(UserSession::class);
        }

        $this->forget($publicId);

        LogService::info('Sesión revocada', ['user_id' => $user->id, 'session' => $publicId, 'reason' => $reason], 'security');
    }

    public function revokeOthers(User $user, ?string $exceptSid, string $reason = 'user'): int
    {
        $query = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at');

        if ($exceptSid !== null) {
            $query->where('public_id', '!=', $exceptSid);
        }

        $ids = $query->pluck('public_id')->all();

        if ($ids === []) {
            return 0;
        }

        $afectadas = UserSession::query()
            ->where('user_id', $user->id)
            ->whereIn('public_id', $ids)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        foreach ($ids as $id) {
            $this->forget($id);
        }

        LogService::info('Sesiones revocadas', ['user_id' => $user->id, 'count' => $afectadas, 'reason' => $reason], 'security');

        return $afectadas;
    }

    public function revokeBySid(string $sid, string $reason = 'logout'): void
    {
        UserSession::query()
            ->where('public_id', $sid)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        $this->forget($sid);
    }

    public function isActive(string $sid): bool
    {
        $key = $this->activeKey($sid);

        if (Cache::get($key) === true) {
            return true;
        }

        $activa = UserSession::query()->where('public_id', $sid)->whereNull('revoked_at')->exists();

        // Solo se cachea el "sí": un "no" no necesita caché y así una sesión nunca queda
        // bloqueada por un valor viejo.
        if ($activa) {
            Cache::put($key, true, (int) config('auth.sessions.cache_ttl', 30));
        }

        return $activa;
    }

    public function touch(string $sid): void
    {
        $intervalo = (int) config('auth.sessions.touch_interval', 60);

        if ($intervalo <= 0 || ! Cache::add('user_session:touch:'.$sid, 1, $intervalo)) {
            return;
        }

        UserSession::query()->where('public_id', $sid)->whereNull('revoked_at')->update(['last_used_at' => now()]);
    }

    public function rotate(string $sid, int $userId, string $presentedJti, string $newJti): string
    {
        // Compare-and-swap en una sola sentencia: de dos refresh simultáneos con el mismo
        // token, la BD deja pasar solo a uno. El vigente pasa a `previous_refresh_jti`.
        $ganada = UserSession::query()
            ->where('public_id', $sid)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('refresh_jti', $presentedJti)
            ->update([
                'previous_refresh_jti' => DB::raw('refresh_jti'),
                'refresh_jti' => $newJti,
                'refresh_rotated_at' => now(),
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        if ($ganada === 1) {
            return self::ROTATED;
        }

        $sesion = UserSession::query()->where('public_id', $sid)->where('user_id', $userId)->first();

        if ($sesion === null || $sesion->isRevoked()) {
            return self::REJECTED;
        }

        $gracia = (int) config('auth.sessions.refresh_reuse_grace_seconds', 10);

        // El inmediatamente anterior, recién rotado: carrera legítima (dos pestañas, reintento de red).
        if (
            $gracia > 0
            && $sesion->previous_refresh_jti !== null
            && hash_equals($sesion->previous_refresh_jti, $presentedJti)
            && $sesion->refresh_rotated_at !== null
            && $sesion->refresh_rotated_at->greaterThan(now()->subSeconds($gracia))
        ) {
            LogService::info('Refresh superado dentro de la gracia: sesión intacta', [
                'user_id' => $userId,
                'session' => $sid,
            ], 'auth');

            return self::SUPERSEDED;
        }

        // Reutilización real (robo): se cierra la sesión entera.
        $this->revokeBySid($sid, 'refresh_reuse');

        LogService::warning('Reutilización de refresh token: sesión revocada', [
            'user_id' => $userId,
            'session' => $sid,
        ], 'security');

        return self::REJECTED;
    }

    private function activeKey(string $sid): string
    {
        return 'user_session:active:'.$sid;
    }

    private function forget(string $sid): void
    {
        Cache::forget($this->activeKey($sid));
    }
}
