<?php

namespace App\Services\Logging;

use App\Models\Logs\AuthEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class AuthLogger
{
    /**
     * Log a successful login event.
     */
    public static function logLogin($userId, Request $request, ?string $jti = null): void
    {
        self::logAuthEvent(
            userId: $userId,
            event: 'login',
            result: 'success',
            request: $request,
            jti: $jti
        );
    }

    /**
     * Log a failed login attempt.
     */
    public static function logLoginFailed(Request $request, string $reason = 'Invalid credentials'): void
    {
        self::logAuthEvent(
            userId: null,
            event: 'login',
            result: 'failed',
            request: $request,
            reason: $reason
        );
    }

    /**
     * Log a successful token refresh.
     */
    public static function logRefresh($userId, Request $request, ?string $oldJti = null, ?string $newJti = null): void
    {
        self::logAuthEvent(
            userId: $userId,
            event: 'refresh',
            result: 'success',
            request: $request,
            jti: $newJti,
            meta: $oldJti ? ['old_jti' => $oldJti] : null
        );
    }

    /**
     * Log a failed token refresh.
     */
    public static function logRefreshFailed(Request $request, string $reason = 'Invalid refresh token'): void
    {
        self::logAuthEvent(
            userId: null,
            event: 'refresh',
            result: 'failed',
            request: $request,
            reason: $reason
        );
    }

    /**
     * Log a logout event.
     */
    public static function logLogout($userId, Request $request, ?string $jti = null): void
    {
        self::logAuthEvent(
            userId: $userId,
            event: 'logout',
            result: 'success',
            request: $request,
            jti: $jti
        );
    }

    /**
     * Log a blocked authentication attempt.
     */
    public static function logBlocked(Request $request, string $reason = 'Rate limit exceeded'): void
    {
        self::logAuthEvent(
            userId: null,
            event: 'blocked',
            result: 'blocked',
            request: $request,
            reason: $reason
        );
    }

    /**
     * Log a 2FA success event.
     */
    public static function log2FASuccess($userId, Request $request): void
    {
        self::logAuthEvent(
            userId: $userId,
            event: '2fa_success',
            result: 'success',
            request: $request
        );
    }

    /**
     * Log a 2FA failure event.
     */
    public static function log2FAFailed($userId, Request $request, string $reason = 'Invalid 2FA code'): void
    {
        self::logAuthEvent(
            userId: $userId,
            event: '2fa_failed',
            result: 'failed',
            request: $request,
            reason: $reason
        );
    }

    /**
     * Core method to log authentication events.
     */
    private static function logAuthEvent(
        $userId,
        string $event,
        string $result,
        Request $request,
        ?string $reason = null,
        ?string $jti = null,
        ?array $meta = null
    ): void {
        $data = [
            'user_id' => $userId,
            'event' => $event,
            'result' => $result,
            'reason' => $reason,
            'jti' => $jti,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => $meta,
            'trace_id' => $request->attributes->get('trace_id'),
        ];

        // Si estamos en modo asíncrono (recomendado para producción)
        if (config('logging.async', true)) {
            Queue::push(function () use ($data) {
                AuthEvent::create($data);
            });
        } else {
            // Modo síncrono para desarrollo/testing
            AuthEvent::create($data);
        }
    }

    /**
     * Get recent auth events for a user.
     */
    public static function getRecentEventsForUser($userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return AuthEvent::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed login attempts from an IP.
     */
    public static function getFailedAttemptsFromIp(string $ip, int $minutes = 60): int
    {
        return AuthEvent::failed()
            ->fromIp($ip)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Check if an IP is potentially suspicious.
     */
    public static function isSuspiciousIp(string $ip, int $failedThreshold = 5, int $minutes = 30): bool
    {
        return self::getFailedAttemptsFromIp($ip, $minutes) >= $failedThreshold;
    }
}
