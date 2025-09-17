<?php

namespace App\Services\Logging;

use App\Models\Logs\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class SecurityLogger
{
    /**
     * Severity levels.
     */
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Log a security event.
     */
    public static function log(
        string $severity,
        string $event,
        ?string $userId = null,
        ?array $context = null,
        ?Request $request = null
    ): void {
        $request = $request ?? request();
        
        $data = [
            'severity' => $severity,
            'event' => $event,
            'user_id' => $userId,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'context' => $context,
            'trace_id' => $request?->attributes->get('trace_id'),
        ];

        // Si estamos en modo asíncrono (recomendado para producción)
        if (config('logging.async', true)) {
            Queue::push(function () use ($data) {
                SecurityEvent::create($data);
            });
        } else {
            // Modo síncrono para desarrollo/testing
            SecurityEvent::create($data);
        }
    }

    /**
     * Log rate limit exceeded.
     */
    public static function logRateLimitExceeded(?string $userId = null, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_MEDIUM,
            event: 'rate_limit_exceeded',
            userId: $userId,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod()
            ]),
            request: $request
        );
    }

    /**
     * Log invalid webhook signature.
     */
    public static function logInvalidWebhookSignature(?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_HIGH,
            event: 'invalid_webhook_signature',
            userId: null,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'content_length' => $request?->header('Content-Length')
            ]),
            request: $request
        );
    }

    /**
     * Log blocked file upload.
     */
    public static function logUploadBlocked(string $userId, string $reason, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_MEDIUM,
            event: 'upload_blocked',
            userId: $userId,
            context: array_merge($context ?? [], [
                'reason' => $reason,
                'file_size' => $request?->header('Content-Length')
            ]),
            request: $request
        );
    }

    /**
     * Log suspicious login pattern.
     */
    public static function logSuspiciousLogin(string $userId, string $reason, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_HIGH,
            event: 'suspicious_login',
            userId: $userId,
            context: array_merge($context ?? [], [
                'reason' => $reason
            ]),
            request: $request
        );
    }

    /**
     * Log unauthorized access attempt.
     */
    public static function logUnauthorizedAccess(?string $userId = null, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_HIGH,
            event: 'unauthorized_access',
            userId: $userId,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod()
            ]),
            request: $request
        );
    }

    /**
     * Log potential SQL injection attempt.
     */
    public static function logSqlInjectionAttempt(?string $userId = null, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_CRITICAL,
            event: 'sql_injection_attempt',
            userId: $userId,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'query_string' => $request?->getQueryString()
            ]),
            request: $request
        );
    }

    /**
     * Log potential XSS attempt.
     */
    public static function logXssAttempt(?string $userId = null, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_HIGH,
            event: 'xss_attempt',
            userId: $userId,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'referer' => $request?->header('Referer')
            ]),
            request: $request
        );
    }

    /**
     * Log brute force attempt.
     */
    public static function logBruteForceAttempt(?string $userId = null, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_CRITICAL,
            event: 'brute_force_attempt',
            userId: $userId,
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo()
            ]),
            request: $request
        );
    }

    /**
     * Log account lockout.
     */
    public static function logAccountLockout(string $userId, string $reason, ?array $context = null, ?Request $request = null): void
    {
        self::log(
            severity: self::SEVERITY_HIGH,
            event: 'account_lockout',
            userId: $userId,
            context: array_merge($context ?? [], [
                'reason' => $reason
            ]),
            request: $request
        );
    }

    /**
     * Get recent security events by severity.
     */
    public static function getRecentEventsBySeverity(string $severity, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return SecurityEvent::severity($severity)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get critical events from last hours.
     */
    public static function getCriticalEventsLastHours(int $hours = 24): \Illuminate\Database\Eloquent\Collection
    {
        return SecurityEvent::critical()
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get security events for a specific IP.
     */
    public static function getEventsForIp(string $ip, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return SecurityEvent::fromIp($ip)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if an IP has too many security events.
     */
    public static function isIpSuspicious(string $ip, int $threshold = 10, int $hours = 24): bool
    {
        $count = SecurityEvent::fromIp($ip)
            ->where('created_at', '>=', now()->subHours($hours))
            ->count();

        return $count >= $threshold;
    }

    /**
     * Log exceptions with security relevance.
     */
    public static function logException(\Throwable $exception, ?Request $request = null, ?int $statusCode = null): void
    {
        $request = $request ?? request();
        $statusCode = $statusCode ?? 500;
        
        // Determinar severidad basada en el tipo de excepción y código de estado
        $severity = self::determineSeverityForException($exception, $statusCode);
        $eventType = self::getEventTypeForException($exception);
        
        self::log(
            severity: $severity,
            event: $eventType,
            userId: $request?->user()?->id,
            context: [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'status_code' => $statusCode,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'stack_trace_preview' => self::getStackTracePreview($exception)
            ],
            request: $request
        );
    }

    /**
     * Determine severity for exceptions.
     */
    private static function determineSeverityForException(\Throwable $exception, int $statusCode): string
    {
        // Excepciones críticas
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException ||
            $statusCode === 429) {
            return self::SEVERITY_HIGH;
        }
        
        // Errores de autenticación/autorización
        if ($statusCode === 401 || $statusCode === 403) {
            return self::SEVERITY_MEDIUM;
        }
        
        // Errores de validación/cliente
        if ($statusCode >= 400 && $statusCode < 500) {
            return self::SEVERITY_LOW;
        }
        
        // Errores del servidor
        if ($statusCode >= 500) {
            return self::SEVERITY_MEDIUM;
        }
        
        return self::SEVERITY_LOW;
    }

    /**
     * Get event type based on exception.
     */
    private static function getEventTypeForException(\Throwable $exception): string
    {
        $className = class_basename($exception);
        
        $eventMap = [
            'TooManyRequestsHttpException' => 'rate_limit_exception',
            'AuthenticationException' => 'authentication_exception',
            'AuthorizationException' => 'authorization_exception',
            'ValidationException' => 'validation_exception',
            'ModelNotFoundException' => 'resource_not_found',
            'QueryException' => 'database_exception',
            'HttpException' => 'http_exception',
        ];
        
        return $eventMap[$className] ?? 'general_exception';
    }

    /**
     * Get a preview of the stack trace for logging.
     */
    private static function getStackTracePreview(\Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $preview = [];
        
        // Solo tomar los primeros 3 frames del stack trace
        for ($i = 0; $i < min(3, count($trace)); $i++) {
            $frame = $trace[$i];
            $preview[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 'unknown',
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }
        
        return $preview;
    }
}
