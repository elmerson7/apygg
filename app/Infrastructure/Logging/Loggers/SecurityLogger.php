<?php

namespace App\Infrastructure\Logging\Loggers;

use App\Infrastructure\Logging\Models\SecurityLog;
use App\Infrastructure\Services\LogService;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * SecurityLogger
 *
 * Logger especializado para registrar eventos de seguridad.
 * Registra permisos denegados, actividades sospechosas, bloqueos de cuenta, etc.
 *
 * @package App\Infrastructure\Logging\Loggers
 */
class SecurityLogger
{
    /**
     * Registrar permiso denegado
     *
     * @param User|null $user Usuario que intentó acceder
     * @param string $permission Permiso requerido
     * @param string|null $resource Recurso al que intentó acceder
     * @param Request|null $request Request actual
     * @return SecurityLog|null
     */
    public static function logPermissionDenied(
        ?User $user,
        string $permission,
        ?string $resource = null,
        ?Request $request = null
    ): ?SecurityLog {
        try {
            $request = $request ?? request();

            return SecurityLog::create([
                'user_id' => $user?->id,
                'event_type' => SecurityLog::EVENT_PERMISSION_DENIED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'permission' => $permission,
                    'resource' => $resource,
                    'route' => $request->route()?->getName(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'denied_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log permission denied', [
                'user_id' => $user?->id,
                'permission' => $permission,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar actividad sospechosa
     *
     * @param string $description Descripción de la actividad sospechosa
     * @param User|null $user Usuario relacionado
     * @param array $details Detalles adicionales
     * @param Request|null $request Request actual
     * @return SecurityLog|null
     */
    public static function logSuspiciousActivity(
        string $description,
        ?User $user = null,
        array $details = [],
        ?Request $request = null
    ): ?SecurityLog {
        try {
            $request = $request ?? request();

            $log = SecurityLog::create([
                'user_id' => $user?->id,
                'event_type' => SecurityLog::EVENT_SUSPICIOUS_ACTIVITY,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => array_merge([
                    'description' => $description,
                    'detected_at' => now()->toIso8601String(),
                    'route' => $request->route()?->getName(),
                    'url' => $request->fullUrl(),
                ], $details),
            ]);

            // Log crítico para actividades sospechosas
            LogService::critical('Suspicious activity detected', [
                'user_id' => $user?->id,
                'description' => $description,
                'details' => $details,
            ]);

            return $log;
        } catch (\Exception $e) {
            LogService::error('Failed to log suspicious activity', [
                'user_id' => $user?->id,
                'description' => $description,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar bloqueo de cuenta
     *
     * @param User $user Usuario bloqueado
     * @param string $reason Razón del bloqueo
     * @param User|null $blockedBy Usuario que bloqueó (si es admin)
     * @return SecurityLog|null
     */
    public static function logAccountLocked(
        User $user,
        string $reason,
        ?User $blockedBy = null
    ): ?SecurityLog {
        try {
            return SecurityLog::create([
                'user_id' => $user->id,
                'event_type' => SecurityLog::EVENT_ACCOUNT_LOCKED,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => [
                    'reason' => $reason,
                    'locked_by' => $blockedBy?->id,
                    'locked_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log account locked', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar desbloqueo de cuenta
     *
     * @param User $user Usuario desbloqueado
     * @param User|null $unlockedBy Usuario que desbloqueó (si es admin)
     * @return SecurityLog|null
     */
    public static function logAccountUnlocked(
        User $user,
        ?User $unlockedBy = null
    ): ?SecurityLog {
        try {
            return SecurityLog::create([
                'user_id' => $user->id,
                'event_type' => SecurityLog::EVENT_ACCOUNT_UNLOCKED,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => [
                    'unlocked_by' => $unlockedBy?->id,
                    'unlocked_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log account unlocked', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar evento de seguridad personalizado
     *
     * @param string $eventType Tipo de evento (debe ser uno de los EVENT_*)
     * @param User|null $user Usuario relacionado
     * @param array $details Detalles del evento
     * @param Request|null $request Request actual
     * @return SecurityLog|null
     */
    public static function logEvent(
        string $eventType,
        ?User $user = null,
        array $details = [],
        ?Request $request = null
    ): ?SecurityLog {
        try {
            $request = $request ?? request();

            return SecurityLog::create([
                'user_id' => $user?->id,
                'event_type' => $eventType,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => array_merge([
                    'logged_at' => now()->toIso8601String(),
                ], $details),
            ]);
        } catch (\Exception $e) {
            LogService::error('Failed to log security event', [
                'event_type' => $eventType,
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
