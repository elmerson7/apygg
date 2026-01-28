<?php

namespace App\Http\Middleware;

use App\Services\Logging\SecurityLogger;
use App\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityLoggerMiddleware
 *
 * Middleware para registrar eventos de seguridad y detectar patrones anómalos.
 * Detecta múltiples denegaciones de acceso, fuerza bruta, comportamiento sospechoso, etc.
 */
class SecurityLoggerMiddleware
{
    /**
     * Límites de detección de patrones anómalos
     */
    private const MAX_DENIED_PER_USER = 5; // Máximo de denegaciones por usuario en ventana de tiempo

    private const MAX_DENIED_PER_IP = 10; // Máximo de denegaciones por IP en ventana de tiempo

    private const TIME_WINDOW_MINUTES = 5; // Ventana de tiempo en minutos

    // private const SUSPICIOUS_ACTIVITY_THRESHOLD = 3; // Umbral para marcar como sospechoso (no usado actualmente)

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Registrar eventos de seguridad según el código de respuesta
        try {
            $statusCode = $response->getStatusCode();
            $user = auth()->user();
            $ipAddress = $request->ip();

            // Registrar eventos de seguridad
            $this->logSecurityEvent($request, $response, $statusCode, $user, $ipAddress);

            // Detectar patrones anómalos
            $this->detectAnomalousPatterns($request, $statusCode, $user, $ipAddress);

        } catch (\Exception $e) {
            // Silenciar errores de logging para no interrumpir el flujo principal
            \Log::warning('Failed to log security event', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }

    /**
     * Registrar evento de seguridad según código de respuesta
     *
     * @param  mixed  $user
     */
    protected function logSecurityEvent(
        Request $request,
        Response $response,
        int $statusCode,
        $user,
        string $ipAddress
    ): void {
        // 401 - No autenticado
        if ($statusCode === 401) {
            SecurityLogger::logPermissionDenied(
                $user,
                'unauthorized',
                $request->path(),
                $request
            );
        }

        // 403 - No autorizado
        if ($statusCode === 403) {
            SecurityLogger::logPermissionDenied(
                $user,
                'forbidden',
                $request->path(),
                $request
            );
        }

        // 429 - Rate limit exceeded
        if ($statusCode === 429) {
            SecurityLogger::logSuspiciousActivity(
                'Rate limit exceeded',
                $user,
                [
                    'ip' => $ipAddress,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
                $request
            );
        }
    }

    /**
     * Detectar patrones anómalos en el comportamiento
     *
     * @param  mixed  $user
     */
    protected function detectAnomalousPatterns(
        Request $request,
        int $statusCode,
        $user,
        string $ipAddress
    ): void {
        // Solo analizar códigos de error relacionados con seguridad
        if (! in_array($statusCode, [401, 403, 429])) {
            return;
        }

        $userId = $user?->id;
        $timeWindow = self::TIME_WINDOW_MINUTES * 60; // Convertir a segundos

        // 1. Detectar múltiples denegaciones por usuario
        if ($userId) {
            $this->detectMultipleDenialsByUser($userId, $ipAddress, $request, $statusCode, $timeWindow);
        }

        // 2. Detectar múltiples denegaciones por IP
        $this->detectMultipleDenialsByIp($ipAddress, $request, $statusCode, $timeWindow);

        // 3. Detectar comportamiento anormal (múltiples IPs, requests muy rápidos)
        $this->detectAbnormalBehavior($request, $user, $ipAddress, $statusCode);

        // 4. Detectar intentos de fuerza bruta
        if ($statusCode === 401) {
            $this->detectBruteForceAttempts($ipAddress, $user, $request);
        }

        // 5. Detectar escalada de privilegios (intentos repetidos de acceso admin)
        if ($statusCode === 403 && $this->isAdminRoute($request->path())) {
            $this->detectPrivilegeEscalation($ipAddress, $user, $request);
        }
    }

    /**
     * Detectar múltiples denegaciones por usuario
     */
    protected function detectMultipleDenialsByUser(
        string $userId,
        string $ipAddress,
        Request $request,
        int $statusCode,
        int $timeWindow
    ): void {
        $cacheKey = "security:denials:user:{$userId}";
        $denials = Cache::get($cacheKey, []);

        // Agregar denegación actual
        $denials[] = [
            'timestamp' => now()->timestamp,
            'ip' => $ipAddress,
            'path' => $request->path(),
            'status' => $statusCode,
        ];

        // Filtrar denegaciones dentro de la ventana de tiempo
        $recentDenials = array_filter($denials, function ($denial) use ($timeWindow) {
            return (now()->timestamp - $denial['timestamp']) <= $timeWindow;
        });

        // Actualizar cache
        Cache::put($cacheKey, array_values($recentDenials), $timeWindow);

        // Si supera el límite, registrar como sospechoso
        if (count($recentDenials) >= self::MAX_DENIED_PER_USER) {
            SecurityLogger::logSuspiciousActivity(
                sprintf(
                    'Multiple access denials detected for user: %d denials in %d minutes',
                    count($recentDenials),
                    self::TIME_WINDOW_MINUTES
                ),
                $request->user(),
                [
                    'denial_count' => count($recentDenials),
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                    'paths' => array_unique(array_column($recentDenials, 'path')),
                    'ips' => array_unique(array_column($recentDenials, 'ip')),
                ],
                $request
            );

            // Log crítico
            LogService::critical('Suspicious user activity: multiple access denials', [
                'user_id' => $userId,
                'denial_count' => count($recentDenials),
                'ip_address' => $ipAddress,
            ]);
        }
    }

    /**
     * Detectar múltiples denegaciones por IP
     */
    protected function detectMultipleDenialsByIp(
        string $ipAddress,
        Request $request,
        int $statusCode,
        int $timeWindow
    ): void {
        $cacheKey = "security:denials:ip:{$ipAddress}";
        $denials = Cache::get($cacheKey, []);

        // Agregar denegación actual
        $denials[] = [
            'timestamp' => now()->timestamp,
            'user_id' => auth()->id(),
            'path' => $request->path(),
            'status' => $statusCode,
        ];

        // Filtrar denegaciones dentro de la ventana de tiempo
        $recentDenials = array_filter($denials, function ($denial) use ($timeWindow) {
            return (now()->timestamp - $denial['timestamp']) <= $timeWindow;
        });

        // Actualizar cache
        Cache::put($cacheKey, array_values($recentDenials), $timeWindow);

        // Si supera el límite, registrar como sospechoso
        if (count($recentDenials) >= self::MAX_DENIED_PER_IP) {
            SecurityLogger::logSuspiciousActivity(
                sprintf(
                    'Multiple access denials detected from IP: %d denials in %d minutes',
                    count($recentDenials),
                    self::TIME_WINDOW_MINUTES
                ),
                null,
                [
                    'ip_address' => $ipAddress,
                    'denial_count' => count($recentDenials),
                    'time_window_minutes' => self::TIME_WINDOW_MINUTES,
                    'paths' => array_unique(array_column($recentDenials, 'path')),
                    'user_ids' => array_unique(array_filter(array_column($recentDenials, 'user_id'))),
                ],
                $request
            );

            // Log crítico
            LogService::critical('Suspicious IP activity: multiple access denials', [
                'ip_address' => $ipAddress,
                'denial_count' => count($recentDenials),
            ]);
        }
    }

    /**
     * Detectar comportamiento anormal
     *
     * @param  mixed  $user
     */
    protected function detectAbnormalBehavior(
        Request $request,
        $user,
        string $ipAddress,
        int $statusCode
    ): void {
        // Detectar requests muy rápidos (posible bot)
        $this->detectRapidRequests($ipAddress, $request);

        // Detectar múltiples IPs para el mismo usuario (si está autenticado)
        if ($user) {
            $this->detectMultipleIpsForUser($user->id, $ipAddress, $request);
        }
    }

    /**
     * Detectar requests muy rápidos (posible bot)
     */
    protected function detectRapidRequests(string $ipAddress, Request $request): void
    {
        $cacheKey = "security:rapid_requests:{$ipAddress}";
        $requests = Cache::get($cacheKey, []);

        $requests[] = now()->timestamp;

        // Filtrar requests en los últimos 10 segundos
        $recentRequests = array_filter($requests, function ($timestamp) {
            return (now()->timestamp - $timestamp) <= 10;
        });

        Cache::put($cacheKey, array_values($recentRequests), 10);

        // Si hay más de 20 requests en 10 segundos, es sospechoso
        if (count($recentRequests) > 20) {
            SecurityLogger::logSuspiciousActivity(
                'Rapid requests detected: possible bot activity',
                null,
                [
                    'ip_address' => $ipAddress,
                    'request_count' => count($recentRequests),
                    'time_window_seconds' => 10,
                    'path' => $request->path(),
                ],
                $request
            );
        }
    }

    /**
     * Detectar múltiples IPs para el mismo usuario
     */
    protected function detectMultipleIpsForUser(string $userId, string $ipAddress, Request $request): void
    {
        $cacheKey = "security:user_ips:{$userId}";
        $ips = Cache::get($cacheKey, []);

        if (! in_array($ipAddress, $ips)) {
            $ips[] = [
                'ip' => $ipAddress,
                'first_seen' => now()->timestamp,
            ];
        }

        // Mantener solo las últimas 5 IPs
        $ips = array_slice($ips, -5);
        Cache::put($cacheKey, $ips, 3600); // 1 hora

        // Si hay más de 3 IPs diferentes en menos de 1 hora, es sospechoso
        if (count($ips) > 3) {
            $recentIps = array_filter($ips, function ($ipData) {
                return (now()->timestamp - $ipData['first_seen']) <= 3600;
            });

            if (count($recentIps) > 3) {
                SecurityLogger::logSuspiciousActivity(
                    'Multiple IPs detected for user: possible account sharing or compromise',
                    $request->user(),
                    [
                        'user_id' => $userId,
                        'ip_count' => count($recentIps),
                        'ips' => array_column($recentIps, 'ip'),
                    ],
                    $request
                );
            }
        }
    }

    /**
     * Detectar intentos de fuerza bruta
     *
     * @param  mixed  $user
     */
    protected function detectBruteForceAttempts(string $ipAddress, $user, Request $request): void
    {
        $cacheKey = "security:brute_force:{$ipAddress}";
        $attempts = Cache::get($cacheKey, []);

        $attempts[] = [
            'timestamp' => now()->timestamp,
            'path' => $request->path(),
            'user_id' => $user?->id,
        ];

        // Filtrar intentos en los últimos 15 minutos
        $recentAttempts = array_filter($attempts, function ($attempt) {
            return (now()->timestamp - $attempt['timestamp']) <= 900; // 15 minutos
        });

        Cache::put($cacheKey, array_values($recentAttempts), 900);

        // Si hay más de 5 intentos fallidos en 15 minutos, es fuerza bruta
        if (count($recentAttempts) >= 5) {
            SecurityLogger::logSuspiciousActivity(
                'Brute force attempt detected: multiple failed authentication attempts',
                $user,
                [
                    'ip_address' => $ipAddress,
                    'attempt_count' => count($recentAttempts),
                    'time_window_minutes' => 15,
                    'paths' => array_unique(array_column($recentAttempts, 'path')),
                ],
                $request
            );

            LogService::critical('Brute force attack detected', [
                'ip_address' => $ipAddress,
                'attempt_count' => count($recentAttempts),
            ]);
        }
    }

    /**
     * Detectar escalada de privilegios
     *
     * @param  mixed  $user
     */
    protected function detectPrivilegeEscalation(string $ipAddress, $user, Request $request): void
    {
        $cacheKey = "security:privilege_escalation:{$ipAddress}";
        $attempts = Cache::get($cacheKey, []);

        $attempts[] = [
            'timestamp' => now()->timestamp,
            'path' => $request->path(),
            'user_id' => $user?->id,
        ];

        // Filtrar intentos en los últimos 30 minutos
        $recentAttempts = array_filter($attempts, function ($attempt) {
            return (now()->timestamp - $attempt['timestamp']) <= 1800; // 30 minutos
        });

        Cache::put($cacheKey, array_values($recentAttempts), 1800);

        // Si hay más de 3 intentos de acceso admin, es escalada de privilegios
        if (count($recentAttempts) >= 3) {
            SecurityLogger::logSuspiciousActivity(
                'Privilege escalation attempt detected: repeated access attempts to admin routes',
                $user,
                [
                    'ip_address' => $ipAddress,
                    'attempt_count' => count($recentAttempts),
                    'time_window_minutes' => 30,
                    'admin_paths' => array_unique(array_column($recentAttempts, 'path')),
                ],
                $request
            );

            LogService::warning('Privilege escalation attempt detected', [
                'ip_address' => $ipAddress,
                'user_id' => $user?->id,
                'attempt_count' => count($recentAttempts),
            ]);
        }
    }

    /**
     * Verificar si una ruta es de administración
     */
    protected function isAdminRoute(string $path): bool
    {
        $adminPatterns = [
            '/admin',
            '/api/admin',
            '/users',
            '/roles',
            '/permissions',
        ];

        foreach ($adminPatterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
