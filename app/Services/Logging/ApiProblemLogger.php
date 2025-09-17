<?php

namespace App\Services\Logging;

use App\Models\Logs\ApiProblemDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ApiProblemLogger
{
    /**
     * Log an API problem detail following RFC-7807.
     */
    public static function log(
        string $type,
        string $title,
        int $status,
        ?string $detail = null,
        ?string $instance = null,
        ?array $context = null,
        ?Request $request = null
    ): void {
        // Verificar si está habilitado
        if (!config('logging.api_problem_enabled', true)) {
            return;
        }

        $request = $request ?? request();
        
        $data = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
            'user_id' => $request?->user()?->id,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'context' => $context,
            'trace_id' => $request?->attributes->get('trace_id'),
        ];

        // Filtrar solo errores relevantes (configurable)
        if (self::shouldLogProblem($status, $type)) {
            if (config('logging.async', true)) {
                Queue::push(function () use ($data) {
                    ApiProblemDetail::create($data);
                });
            } else {
                ApiProblemDetail::create($data);
            }
        }
    }

    /**
     * Log from exception.
     */
    public static function logFromException(Throwable $exception, ?Request $request = null, ?int $statusCode = null): void
    {
        $request = $request ?? request();
        $statusCode = $statusCode ?? 500;
        
        self::log(
            type: 'https://damblix.dev/errors/' . class_basename($exception),
            title: $exception->getMessage() ?: 'Unexpected error',
            status: $statusCode,
            detail: method_exists($exception, 'getHint') ? $exception->getHint() : null,
            instance: $request?->fullUrl(),
            context: [
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'parameters' => self::sanitizeParameters($request?->all() ?? []),
                'has_user' => $request?->user() !== null,
            ],
            request: $request
        );
    }

    /**
     * Log validation errors.
     */
    public static function logValidationError(array $errors, ?Request $request = null): void
    {
        if (!config('logging.api_problem_log_validation', true)) {
            return;
        }

        $request = $request ?? request();
        
        self::log(
            type: 'https://damblix.dev/errors/ValidationException',
            title: 'Validation failed',
            status: 422,
            detail: 'The given data was invalid.',
            instance: $request?->fullUrl(),
            context: [
                'validation_errors' => $errors,
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'failed_fields' => array_keys($errors),
                'total_errors' => count($errors),
                'input_data' => self::sanitizeParameters($request?->all() ?? []),
            ],
            request: $request
        );
    }

    /**
     * Log authentication errors.
     */
    public static function logAuthError(string $reason, int $status = 401, ?Request $request = null): void
    {
        if (!config('logging.api_problem_log_auth', true)) {
            return;
        }

        $request = $request ?? request();
        
        self::log(
            type: 'https://damblix.dev/errors/AuthenticationException',
            title: 'Authentication failed',
            status: $status,
            detail: $reason,
            instance: $request?->fullUrl(),
            context: [
                'auth_reason' => $reason,
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'has_bearer_token' => $request?->bearerToken() !== null,
                'has_auth_header' => $request?->hasHeader('Authorization'),
            ],
            request: $request
        );
    }

    /**
     * Log rate limit exceeded.
     */
    public static function logRateLimitExceeded(?Request $request = null): void
    {
        $request = $request ?? request();
        
        self::log(
            type: 'https://damblix.dev/errors/TooManyRequestsException',
            title: 'Rate limit exceeded',
            status: 429,
            detail: 'Too many requests. Please slow down.',
            instance: $request?->fullUrl(),
            context: [
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'rate_limit_remaining' => $request?->header('X-RateLimit-Remaining'),
                'rate_limit_limit' => $request?->header('X-RateLimit-Limit'),
                'rate_limit_reset' => $request?->header('X-RateLimit-Reset'),
            ],
            request: $request
        );
    }

    /**
     * Log server errors.
     */
    public static function logServerError(string $error, ?Request $request = null, ?array $context = null): void
    {
        $request = $request ?? request();
        
        self::log(
            type: 'https://damblix.dev/errors/InternalServerError',
            title: 'Internal server error',
            status: 500,
            detail: $error,
            instance: $request?->fullUrl(),
            context: array_merge($context ?? [], [
                'endpoint' => $request?->getPathInfo(),
                'method' => $request?->getMethod(),
                'server_error' => $error,
            ]),
            request: $request
        );
    }

    /**
     * Determine if we should log this problem.
     */
    private static function shouldLogProblem(int $status, string $type): bool
    {
        // No logear 404s si está configurado así
        if ($status === 404 && !config('logging.api_problem_log_404', false)) {
            return false;
        }

        // No logear errores muy comunes que generan mucho ruido
        $ignoredTypes = config('logging.api_problem_ignored_types', [
            'https://damblix.dev/errors/NotFoundHttpException',
        ]);
        
        if (in_array($type, $ignoredTypes)) {
            return false;
        }
        
        // Solo logear errores 4xx y 5xx
        return $status >= 400;
    }

    /**
     * Sanitize parameters to remove sensitive data.
     */
    private static function sanitizeParameters(array $parameters): array
    {
        $sensitiveFields = [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'token',
            'secret',
            'api_key',
            'authorization',
            'credit_card',
            'card_number',
            'cvv',
            'ssn',
        ];

        $sanitized = [];
        foreach ($parameters as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeParameters($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get problem details statistics.
     */
    public static function getErrorStats(int $days = 7): array
    {
        $since = now()->subDays($days);
        
        return [
            'total_errors' => ApiProblemDetail::where('created_at', '>=', $since)->count(),
            'by_status' => ApiProblemDetail::where('created_at', '>=', $since)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->orderBy('count', 'desc')
                ->get()
                ->toArray(),
            'by_type' => ApiProblemDetail::where('created_at', '>=', $since)
                ->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->toArray(),
            'top_endpoints' => ApiProblemDetail::where('created_at', '>=', $since)
                ->whereNotNull('instance')
                ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(context, "$.endpoint")) as endpoint, count(*) as count')
                ->groupBy('endpoint')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->toArray(),
            'error_rate_by_day' => ApiProblemDetail::where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) as date, count(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->toArray(),
        ];
    }

    /**
     * Get errors for a specific user.
     */
    public static function getUserErrors(string $userId, int $hours = 24): \Illuminate\Database\Eloquent\Collection
    {
        return ApiProblemDetail::forUser($userId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get errors by trace ID for debugging.
     */
    public static function getErrorsByTraceId(string $traceId): \Illuminate\Database\Eloquent\Collection
    {
        return ApiProblemDetail::traceId($traceId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get most problematic endpoints.
     */
    public static function getProblematicEndpoints(int $days = 7, int $limit = 10): array
    {
        $since = now()->subDays($days);
        
        return ApiProblemDetail::where('created_at', '>=', $since)
            ->where('status', '>=', 500) // Solo errores del servidor
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(context, "$.endpoint")) as endpoint, count(*) as error_count, avg(status) as avg_status')
            ->groupBy('endpoint')
            ->orderBy('error_count', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get error trends for monitoring.
     */
    public static function getErrorTrends(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        
        return [
            'client_errors' => ApiProblemDetail::clientErrors()
                ->where('created_at', '>=', $since)
                ->selectRaw('HOUR(created_at) as hour, count(*) as count')
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->toArray(),
            'server_errors' => ApiProblemDetail::serverErrors()
                ->where('created_at', '>=', $since)
                ->selectRaw('HOUR(created_at) as hour, count(*) as count')
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->toArray(),
        ];
    }
}
