<?php

namespace App\Http\Middleware;

use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RateLimitLogger
{
    /**
     * Handle an incoming request and log rate limit events.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        // Log rate limit exceeded events
        if ($response->getStatusCode() === 429) {
            $this->logRateLimitExceeded($request, $response);
        }

        return $response;
    }

    /**
     * Log rate limit exceeded events.
     */
    private function logRateLimitExceeded(Request $request, SymfonyResponse $response): void
    {
        $rateLimitRemaining = $response->headers->get('X-RateLimit-Remaining', 0);
        $rateLimitLimit = $response->headers->get('X-RateLimit-Limit', 'unknown');
        $rateLimitReset = $response->headers->get('X-RateLimit-Reset');
        
        // Determinar severidad basado en cuántas veces ha excedido el límite
        $severity = $this->determineSeverity($request);

        SecurityLogger::log(
            severity: $severity,
            event: 'rate_limit_exceeded',
            userId: $request->user()?->id,
            context: [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'rate_limit_remaining' => $rateLimitRemaining,
                'rate_limit_limit' => $rateLimitLimit,
                'rate_limit_reset' => $rateLimitReset,
                'user_agent' => $request->userAgent(),
                'query_params' => $request->query(),
                'is_authenticated' => $request->user() !== null
            ],
            request: $request
        );
    }

    /**
     * Determine severity based on frequency of rate limit violations.
     */
    private function determineSeverity(Request $request): string
    {
        $ip = $request->ip();
        $userId = $request->user()?->id;
        
        // Usar cache para trackear violaciones recientes
        $cacheKey = 'rate_limit_violations:' . ($userId ?: $ip);
        $violations = cache()->get($cacheKey, 0);
        
        // Incrementar contador de violaciones
        cache()->put($cacheKey, $violations + 1, now()->addMinutes(60));
        
        // Determinar severidad basado en número de violaciones
        if ($violations >= 20) {
            return SecurityLogger::SEVERITY_CRITICAL;
        } elseif ($violations >= 10) {
            return SecurityLogger::SEVERITY_HIGH;
        } elseif ($violations >= 5) {
            return SecurityLogger::SEVERITY_MEDIUM;
        } else {
            return SecurityLogger::SEVERITY_LOW;
        }
    }
}
