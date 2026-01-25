<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdaptiveRateLimitingMiddleware
 *
 * Middleware para aplicar rate limiting adaptativo según el tipo de endpoint.
 * Detecta automáticamente si es auth, lectura, escritura o admin y aplica el límite correspondiente.
 *
 * @package App\Http\Middleware
 */
class AdaptiveRateLimitingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar si la ruta está en excepciones
        if ($this->isExcepted($request)) {
            return $next($request);
        }

        // Determinar tipo de endpoint
        $endpointType = $this->getEndpointType($request);

        // Obtener configuración del límite
        $limitConfig = config("rate-limiting.limits.{$endpointType}");

        if (!$limitConfig) {
            // Si no hay configuración, usar límite por defecto
            $limitConfig = config('rate-limiting.limits.read');
        }

        // Determinar identificador (IP o usuario)
        $identifier = $this->getIdentifier($request, $limitConfig['by']);

        // Generar key para el rate limiter
        $key = $this->generateKey($endpointType, $identifier);

        // Verificar límite
        $maxAttempts = $limitConfig['max_attempts'];
        $decayMinutes = $limitConfig['decay_minutes'];

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->buildRateLimitResponse($maxAttempts, $seconds);
        }

        // Incrementar contador
        RateLimiter::hit($key, $decayMinutes * 60);

        // Procesar request
        $response = $next($request);

        // Agregar headers informativos si está habilitado
        if (config('rate-limiting.include_headers', true)) {
            $remaining = max(0, $maxAttempts - RateLimiter::attempts($key));
            $resetAt = now()->addSeconds($decayMinutes * 60)->timestamp;

            $response->headers->set('X-RateLimit-Limit', $maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', $remaining);
            $response->headers->set('X-RateLimit-Reset', $resetAt);
        }

        return $response;
    }

    /**
     * Determinar tipo de endpoint según la ruta y método HTTP
     *
     * @param Request $request
     * @return string
     */
    protected function getEndpointType(Request $request): string
    {
        $path = $request->path();
        $method = $request->method();

        // Verificar patrones específicos primero
        $patterns = config('rate-limiting.patterns', []);

        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if ($this->matchesPattern($path, $pattern)) {
                    return $type;
                }
            }
        }

        // Detectar por método HTTP si no hay patrón específico
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            // Endpoints de escritura que no sean auth
            if (!$this->isAuthEndpoint($path)) {
                return 'write';
            }
        } elseif ($method === 'GET') {
            return 'read';
        }

        // Por defecto, tratar como lectura
        return 'read';
    }

    /**
     * Verificar si la ruta coincide con un patrón
     *
     * @param string $path
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Convertir patrón wildcard a regex
        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));

        return (bool) preg_match("/^{$pattern}$/", $path);
    }

    /**
     * Verificar si es un endpoint de autenticación
     *
     * @param string $path
     * @return bool
     */
    protected function isAuthEndpoint(string $path): bool
    {
        $authPatterns = config('rate-limiting.patterns.auth', []);

        foreach ($authPatterns as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener identificador para el rate limiting (IP o usuario)
     *
     * @param Request $request
     * @param string $by 'ip' o 'user'
     * @return string
     */
    protected function getIdentifier(Request $request, string $by): string
    {
        if ($by === 'user' && auth()->check()) {
            return 'user:' . auth()->id();
        }

        return 'ip:' . $request->ip();
    }

    /**
     * Generar key única para el rate limiter
     *
     * @param string $endpointType
     * @param string $identifier
     * @return string
     */
    protected function generateKey(string $endpointType, string $identifier): string
    {
        return "rate_limit:{$endpointType}:{$identifier}";
    }

    /**
     * Verificar si la ruta está en excepciones
     *
     * @param Request $request
     * @return bool
     */
    protected function isExcepted(Request $request): bool
    {
        $exceptions = config('rate-limiting.except', []);

        foreach ($exceptions as $exception) {
            if ($this->matchesPattern($request->path(), $exception)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Construir respuesta de rate limit excedido
     *
     * @param int $maxAttempts
     * @param int $seconds
     * @return Response
     */
    protected function buildRateLimitResponse(int $maxAttempts, int $seconds): Response
    {
        $message = config('rate-limiting.error_message', 'Demasiadas peticiones. Por favor, intenta de nuevo más tarde.');

        $response = response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'type' => 'rate_limit_exceeded',
                'limit' => $maxAttempts,
                'retry_after' => $seconds,
            ],
        ], 429);

        // Agregar headers informativos
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', 0);
        $response->headers->set('Retry-After', $seconds);
        $response->headers->set('X-RateLimit-Reset', now()->addSeconds($seconds)->timestamp);

        return $response;
    }
}
