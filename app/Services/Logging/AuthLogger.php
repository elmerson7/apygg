<?php

namespace App\Services\Logging;

use App\Models\Logs\SecurityLog;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Support\Facades\Cache;

/**
 * AuthLogger
 *
 * Logger especializado para registrar eventos de autenticación.
 * Registra intentos de login, fallos, cambios de contraseña, etc.
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
     * @param  string  $email  Email o identificador usado
     * @param  string|null  $reason  Razón del fallo
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
     * @param  string|null  $tokenId  ID del token revocado
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
     */
    public static function hasSuspiciousActivity(string $ipAddress, ?string $email = null): bool
    {
        $cacheKey = "auth_failures:{$ipAddress}:".($email ?? 'unknown');

        $failures = Cache::get($cacheKey, 0);

        return $failures >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Verificar y registrar actividad sospechosa
     */
    protected static function checkSuspiciousActivity(string $ipAddress, ?string $email = null): void
    {
        $cacheKey = "auth_failures:{$ipAddress}:".($email ?? 'unknown');

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

            // Log crítico (solo si no estamos en modo testing)
            // Verificar tanto runningUnitTests como APP_ENV para mayor seguridad
            $isTesting = app()->runningUnitTests() 
                || app()->runningInConsole() 
                || config('app.env') === 'testing';
            
            if (! $isTesting) {
                LogService::critical('Suspicious login activity detected', [
                    'ip_address' => $ipAddress,
                    'email' => $email,
                    'failed_attempts' => $failures,
                ]);
            }
        } else {
            // Establecer TTL en el primer incremento
            if ($failures === 1) {
                Cache::put($cacheKey, $failures, self::SUSPICIOUS_ACTIVITY_CACHE_TTL);
            }
        }
    }

    /**
     * Obtener número de intentos fallidos desde una IP
     */
    public static function getFailedAttempts(string $ipAddress, ?string $email = null): int
    {
        $cacheKey = "auth_failures:{$ipAddress}:".($email ?? 'unknown');

        return Cache::get($cacheKey, 0);
    }

    /**
     * Limpiar contador de intentos fallidos
     */
    public static function clearFailedAttempts(string $ipAddress, ?string $email = null): void
    {
        $cacheKey = "auth_failures:{$ipAddress}:".($email ?? 'unknown');
        Cache::forget($cacheKey);
    }
}
