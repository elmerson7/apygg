<?php

namespace App\Http\Middleware;

use App\Infrastructure\Logging\Loggers\SecurityLogger;
use App\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * RateLimitLoggerMiddleware
 *
 * Middleware para registrar intentos bloqueados por rate limiting y detectar patrones de abuso.
 * Detecta múltiples bloqueos, intentos repetidos después del bloqueo, posibles ataques DDoS, etc.
 *
 * @package App\Http\Middleware
 */
class RateLimitLoggerMiddleware
{
    /**
     * Límites de detección de abuso
     */
    private const MAX_BLOCKS_PER_IP = 5; // Máximo de bloqueos por IP antes de alertar
    private const MAX_BLOCKS_PER_USER = 3; // Máximo de bloqueos por usuario antes de alertar
    private const TIME_WINDOW_MINUTES = 15; // Ventana de tiempo en minutos
    private const ABUSE_THRESHOLD = 3; // Umbral para marcar como abuso sistemático

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Registrar bloqueos por rate limiting (429)
        try {
            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                $this->logRateLimitBlock($request, $response);
                $this->detectAbusePatterns($request, $response);
            }
        } catch (\Exception $e) {
            // Silenciar errores de logging para no interrumpir el flujo principal
            \Log::warning('Failed to log rate limit block', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }

    /**
     * Registrar bloqueo por rate limiting
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    protected function logRateLimitBlock(Request $request, Response $response): void
    {
        $user = auth()->user();
        $ipAddress = $request->ip();
        $path = $request->path();
        $method = $request->method();

        // Obtener información de rate limit de los headers
        $rateLimitLimit = $response->headers->get('X-RateLimit-Limit');
        $rateLimitRemaining = $response->headers->get('X-RateLimit-Remaining');
        $rateLimitReset = $response->headers->get('X-RateLimit-Reset');
        $retryAfter = $response->headers->get('Retry-After');

        // Registrar en SecurityLog
        SecurityLogger::logSuspiciousActivity(
            'Rate limit exceeded: request blocked',
            $user,
            [
                'ip_address' => $ipAddress,
                'path' => $path,
                'method' => $method,
                'rate_limit_limit' => $rateLimitLimit,
                'rate_limit_remaining' => $rateLimitRemaining,
                'rate_limit_reset' => $rateLimitReset,
                'retry_after_seconds' => $retryAfter,
                'blocked_at' => now()->toIso8601String(),
                'user_agent' => $request->userAgent(),
            ],
            $request
        );

        // Incrementar contador de bloqueos para análisis
        $this->incrementBlockCounter($ipAddress, $user?->id, $path);
    }

    /**
     * Incrementar contador de bloqueos
     *
     * @param string $ipAddress
     * @param string|null $userId
     * @param string $path
     * @return void
     */
    protected function incrementBlockCounter(string $ipAddress, ?string $userId, string $path): void
    {
        $timeWindow = self::TIME_WINDOW_MINUTES * 60; // Convertir a segundos

        // Contador por IP
        $ipKey = "rate_limit:blocks:ip:{$ipAddress}";
        $ipBlocks = Cache::get($ipKey, []);
        $ipBlocks[] = [
            'timestamp' => now()->timestamp,
            'path' => $path,
            'user_id' => $userId,
        ];
        // Filtrar bloqueos dentro de la ventana de tiempo
        $ipBlocks = array_filter($ipBlocks, function ($block) use ($timeWindow) {
            return (now()->timestamp - $block['timestamp']) <= $timeWindow;
        });
        Cache::put($ipKey, array_values($ipBlocks), $timeWindow);

        // Contador por usuario (si está autenticado)
        if ($userId) {
            $userKey = "rate_limit:blocks:user:{$userId}";
            $userBlocks = Cache::get($userKey, []);
            $userBlocks[] = [
                'timestamp' => now()->timestamp,
                'path' => $path,
                'ip' => $ipAddress,
            ];
            // Filtrar bloqueos dentro de la ventana de tiempo
            $userBlocks = array_filter($userBlocks, function ($block) use ($timeWindow) {
                return (now()->timestamp - $block['timestamp']) <= $timeWindow;
            });
            Cache::put($userKey, array_values($userBlocks), $timeWindow);
        }
    }

    /**
     * Detectar patrones de abuso
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    protected function detectAbusePatterns(Request $request, Response $response): void
    {
        $user = auth()->user();
        $ipAddress = $request->ip();
        $userId = $user?->id;
        $timeWindow = self::TIME_WINDOW_MINUTES * 60;

        // 1. Detectar múltiples bloqueos por IP
        $this->detectMultipleBlocksByIp($ipAddress, $request, $timeWindow);

        // 2. Detectar múltiples bloqueos por usuario
        if ($userId) {
            $this->detectMultipleBlocksByUser($userId, $ipAddress, $request, $timeWindow);
        }

        // 3. Detectar intentos repetidos después del bloqueo
        $this->detectRepeatedAttemptsAfterBlock($ipAddress, $userId, $request);

        // 4. Detectar posible ataque DDoS (muchos bloqueos desde diferentes IPs al mismo endpoint)
        $this->detectDdosPattern($request, $timeWindow);

        // 5. Detectar scraping sistemático (múltiples endpoints bloqueados)
        $this->detectScrapingPattern($ipAddress, $request, $timeWindow);
    }

    /**
     * Detectar múltiples bloqueos por IP
     *
     * @param string $ipAddress
     * @param Request $request
     * @param int $timeWindow
     * @return void
     */
    protected function detectMultipleBlocksByIp(string $ipAddress, Request $request, int $timeWindow): void
    {
        $cacheKey = "rate_limit:blocks:ip:{$ipAddress}";
        $blocks = Cache::get($cacheKey, []);

        // Si supera el límite, es abuso
        if (count($blocks) >= self::MAX_BLOCKS_PER_IP) {
            SecurityLogger::logSuspiciousActivity(
                sprintf(
                    'Rate limit abuse detected from IP: %d blocks in %d minutes',
                    count($blocks),
                    self::TIME_WINDOW_MINUTES
                ),
                null,
                [
                    'ip_address' => $ipAddress,
                    'block_count' => count($blocks),
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                    'paths' => array_unique(array_column($blocks, 'path')),
                    'user_ids' => array_unique(array_filter(array_column($blocks, 'user_id'))),
                ],
                $request
            );

            LogService::critical('Rate limit abuse detected from IP', [
                'ip_address' => $ipAddress,
                'block_count' => count($blocks),
                'paths' => array_unique(array_column($blocks, 'path')),
            ]);
        }
    }

    /**
     * Detectar múltiples bloqueos por usuario
     *
     * @param string $userId
     * @param string $ipAddress
     * @param Request $request
     * @param int $timeWindow
     * @return void
     */
    protected function detectMultipleBlocksByUser(
        string $userId,
        string $ipAddress,
        Request $request,
        int $timeWindow
    ): void {
        $cacheKey = "rate_limit:blocks:user:{$userId}";
        $blocks = Cache::get($cacheKey, []);

        // Si supera el límite, es abuso
        if (count($blocks) >= self::MAX_BLOCKS_PER_USER) {
            SecurityLogger::logSuspiciousActivity(
                sprintf(
                    'Rate limit abuse detected for user: %d blocks in %d minutes',
                    count($blocks),
                    self::TIME_WINDOW_MINUTES
                ),
                $request->user(),
                [
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                    'block_count' => count($blocks),
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                    'paths' => array_unique(array_column($blocks, 'path')),
                    'ips' => array_unique(array_column($blocks, 'ip')),
                ],
                $request
            );

            LogService::warning('Rate limit abuse detected for user', [
                'user_id' => $userId,
                'block_count' => count($blocks),
                'paths' => array_unique(array_column($blocks, 'path')),
            ]);
        }
    }

    /**
     * Detectar intentos repetidos después del bloqueo
     *
     * @param string $ipAddress
     * @param string|null $userId
     * @param Request $request
     * @return void
     */
    protected function detectRepeatedAttemptsAfterBlock(string $ipAddress, ?string $userId, Request $request): void
    {
        $cacheKey = "rate_limit:repeated_attempts:{$ipAddress}";
        $attempts = Cache::get($cacheKey, []);

        $attempts[] = [
            'timestamp' => now()->timestamp,
            'path' => $request->path(),
            'user_id' => $userId,
        ];

        // Filtrar intentos en los últimos 5 minutos
        $recentAttempts = array_filter($attempts, function ($attempt) {
            return (now()->timestamp - $attempt['timestamp']) <= 300; // 5 minutos
        });

        Cache::put($cacheKey, array_values($recentAttempts), 300);

        // Si hay más de 3 intentos repetidos después del bloqueo, es sospechoso
        if (count($recentAttempts) >= 3) {
            SecurityLogger::logSuspiciousActivity(
                'Repeated attempts after rate limit block detected',
                $userId ? $request->user() : null,
                [
                    'ip_address' => $ipAddress,
                    'attempt_count' => count($recentAttempts),
                    'time_window_minutes' => 5,
                    'paths' => array_unique(array_column($recentAttempts, 'path')),
                ],
                $request
            );
        }
    }

    /**
     * Detectar posible patrón DDoS (muchos bloqueos desde diferentes IPs al mismo endpoint)
     *
     * @param Request $request
     * @param int $timeWindow
     * @return void
     */
    protected function detectDdosPattern(Request $request, int $timeWindow): void
    {
        $path = $request->path();
        $cacheKey = "rate_limit:ddos_pattern:{$path}";
        $blocks = Cache::get($cacheKey, []);

        $blocks[] = [
            'timestamp' => now()->timestamp,
            'ip' => $request->ip(),
            'user_id' => auth()->id(),
        ];

        // Filtrar bloqueos en la ventana de tiempo
        $recentBlocks = array_filter($blocks, function ($block) use ($timeWindow) {
            return (now()->timestamp - $block['timestamp']) <= $timeWindow;
        });

        Cache::put($cacheKey, array_values($recentBlocks), $timeWindow);

        // Si hay más de 20 bloqueos desde diferentes IPs al mismo endpoint, posible DDoS
        $uniqueIps = array_unique(array_column($recentBlocks, 'ip'));
        if (count($uniqueIps) >= 20) {
            SecurityLogger::logSuspiciousActivity(
                'Possible DDoS pattern detected: multiple IPs blocked on same endpoint',
                null,
                [
                    'endpoint' => $path,
                    'unique_ips' => count($uniqueIps),
                    'total_blocks' => count($recentBlocks),
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                ],
                $request
            );

            LogService::critical('Possible DDoS pattern detected', [
                'endpoint' => $path,
                'unique_ips' => count($uniqueIps),
                'total_blocks' => count($recentBlocks),
            ]);
        }
    }

    /**
     * Detectar patrón de scraping (múltiples endpoints bloqueados desde la misma IP)
     *
     * @param string $ipAddress
     * @param Request $request
     * @param int $timeWindow
     * @return void
     */
    protected function detectScrapingPattern(string $ipAddress, Request $request, int $timeWindow): void
    {
        $cacheKey = "rate_limit:scraping_pattern:{$ipAddress}";
        $blocks = Cache::get($cacheKey, []);

        $blocks[] = [
            'timestamp' => now()->timestamp,
            'path' => $request->path(),
        ];

        // Filtrar bloqueos en la ventana de tiempo
        $recentBlocks = array_filter($blocks, function ($block) use ($timeWindow) {
            return (now()->timestamp - $block['timestamp']) <= $timeWindow;
        });

        Cache::put($cacheKey, array_values($recentBlocks), $timeWindow);

        // Si hay bloqueos en más de 10 endpoints diferentes, posible scraping
        $uniquePaths = array_unique(array_column($recentBlocks, 'path'));
        if (count($uniquePaths) >= 10) {
            SecurityLogger::logSuspiciousActivity(
                'Possible scraping pattern detected: multiple endpoints blocked from same IP',
                null,
                [
                    'ip_address' => $ipAddress,
                    'unique_endpoints' => count($uniquePaths),
                    'total_blocks' => count($recentBlocks),
                    'endpoints' => $uniquePaths,
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                ],
                $request
            );

            LogService::warning('Possible scraping pattern detected', [
                'ip_address' => $ipAddress,
                'unique_endpoints' => count($uniquePaths),
                'endpoints' => $uniquePaths,
            ]);
        }
    }
}
