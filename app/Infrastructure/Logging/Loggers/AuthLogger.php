<?php

namespace App\Infrastructure\Logging\Loggers;

use App\Infrastructure\Logging\Models\SecurityLog;
use App\Infrastructure\Services\LogService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * AuthLogger
 *
 * Logger especializado para registrar eventos de autenticación.
 * Registra intentos de login, fallos, cambios de contraseña, etc.
 *
 * @package App\Infrastructure\Logging\Loggers
 */
class AuthLogger
{
    /**
     * Tiempo de cache para detección de actividad sospechosa (segundos)
     */
    protected const SUSPICIOUS_ACTIVITY_CACHE_TTL = 3600; // 1 hora

    /**
     * Número máximo de intentos fallidos antes de considerar sospechoso
     */
    protected const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Registrar login exitoso
     *
     * @param User $user
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return SecurityLog|null
     */
    public static function logLoginSuccess(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?SecurityLog {
        try {
            $ipAddress = $ipAddress ?? request()->ip();
            $userAgent = $userAgent ?? request()->userAgent();

            return SecurityLog::create([
                'user_id' => $user->id,
                'event_type' => SecurityLog::EVENT_LOGIN_SUCCESS,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'details' => [
                    'login_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log login success', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar intento de login fallido
     *
     * @param string $email Email o identificador usado
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @param string|null $reason Razón del fallo
     * @return SecurityLog|null
     */
    public static function logLoginFailure(
        string $email,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $reason = null
    ): ?SecurityLog {
        try {
            $ipAddress = $ipAddress ?? request()->ip();
            $userAgent = $userAgent ?? request()->userAgent();

            $log = SecurityLog::create([
                'user_id' => null,
                'event_type' => SecurityLog::EVENT_LOGIN_FAILURE,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'details' => [
                    'email' => $email,
                    'reason' => $reason ?? 'Invalid credentials',
                    'attempted_at' => now()->toIso8601String(),
                ],
            ]);

            // Verificar si hay actividad sospechosa
            self::checkSuspiciousActivity($ipAddress, $email);

            return $log;
        } catch (\Exception $e) {
            LogService::error('Failed to log login failure', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar cambio de contraseña
     *
     * @param User $user
     * @param string|null $ipAddress
     * @return SecurityLog|null
     */
    public static function logPasswordChanged(User $user, ?string $ipAddress = null): ?SecurityLog
    {
        try {
            return SecurityLog::create([
                'user_id' => $user->id,
                'event_type' => SecurityLog::EVENT_PASSWORD_CHANGED,
                'ip_address' => $ipAddress ?? request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => [
                    'changed_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log password change', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar revocación de token
     *
     * @param User $user
     * @param string|null $tokenId ID del token revocado
     * @param string|null $ipAddress
     * @return SecurityLog|null
     */
    public static function logTokenRevoked(User $user, ?string $tokenId = null, ?string $ipAddress = null): ?SecurityLog
    {
        try {
            return SecurityLog::create([
                'user_id' => $user->id,
                'event_type' => SecurityLog::EVENT_TOKEN_REVOKED,
                'ip_address' => $ipAddress ?? request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => [
                    'token_id' => $tokenId,
                    'revoked_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log token revocation', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verificar si hay actividad sospechosa desde una IP o email
     *
     * @param string $ipAddress
     * @param string|null $email
     * @return bool
     */
    public static function hasSuspiciousActivity(string $ipAddress, ?string $email = null): bool
    {
        $cacheKey = "auth_failures:{$ipAddress}:" . ($email ?? 'unknown');

        $failures = Cache::get($cacheKey, 0);

        return $failures >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Verificar y registrar actividad sospechosa
     *
     * @param string $ipAddress
     * @param string|null $email
     * @return void
     */
    protected static function checkSuspiciousActivity(string $ipAddress, ?string $email = null): void
    {
        $cacheKey = "auth_failures:{$ipAddress}:" . ($email ?? 'unknown');

        // Incrementar contador de fallos
        $failures = Cache::increment($cacheKey);

        // Si supera el límite, registrar como sospechoso
        if ($failures >= self::MAX_FAILED_ATTEMPTS) {
            SecurityLog::create([
                'user_id' => null,
                'event_type' => SecurityLog::EVENT_SUSPICIOUS_ACTIVITY,
                'ip_address' => $ipAddress,
                'user_agent' => request()->userAgent(),
                'details' => [
                    'email' => $email,
                    'failed_attempts' => $failures,
                    'detected_at' => now()->toIso8601String(),
                ],
            ]);

            // Log crítico
            LogService::critical('Suspicious login activity detected', [
                'ip_address' => $ipAddress,
                'email' => $email,
                'failed_attempts' => $failures,
            ]);
        } else {
            // Establecer TTL en el primer incremento
            if ($failures === 1) {
                Cache::put($cacheKey, $failures, self::SUSPICIOUS_ACTIVITY_CACHE_TTL);
            }
        }
    }

    /**
     * Obtener número de intentos fallidos desde una IP
     *
     * @param string $ipAddress
     * @param string|null $email
     * @return int
     */
    public static function getFailedAttempts(string $ipAddress, ?string $email = null): int
    {
        $cacheKey = "auth_failures:{$ipAddress}:" . ($email ?? 'unknown');

        return Cache::get($cacheKey, 0);
    }

    /**
     * Limpiar contador de intentos fallidos
     *
     * @param string $ipAddress
     * @param string|null $email
     * @return void
     */
    public static function clearFailedAttempts(string $ipAddress, ?string $email = null): void
    {
        $cacheKey = "auth_failures:{$ipAddress}:" . ($email ?? 'unknown');
        Cache::forget($cacheKey);
    }
}
