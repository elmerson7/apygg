<?php

namespace App\Http\Middleware;

use App\Services\Logging\SecurityLogger as SecurityLoggerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SecurityLogger
{
    /**
     * Handle an incoming request and log security events.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Detectar patrones sospechosos en la URL
        $this->detectSuspiciousUrls($request);
        
        // Detectar headers maliciosos
        $this->detectMaliciousHeaders($request);
        
        // Detectar intentos de inyección en parámetros
        $this->detectInjectionAttempts($request);

        $response = $next($request);

        // Log errores de autenticación
        $this->logAuthenticationErrors($request, $response);
        
        // Log errores de CORS
        $this->logCorsErrors($request, $response);

        return $response;
    }

    /**
     * Detect suspicious URL patterns.
     */
    private function detectSuspiciousUrls(Request $request): void
    {
        $path = $request->getPathInfo();
        $suspiciousPatterns = [
            '/\.\.\//',                    // Path traversal
            '/\/admin/',                   // Admin access attempts
            '/\/wp-admin/',               // WordPress admin attempts
            '/\/wp-login/',               // WordPress login attempts
            '/\/phpmyadmin/',             // PhpMyAdmin attempts
            '/\/\.env/',                  // Environment file access
            '/\/config/',                 // Config file access
            '/\/\.git/',                  // Git repository access
            '/\/backup/',                 // Backup file access
            '/\/database/',               // Database access attempts
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                SecurityLoggerService::log(
                    severity: SecurityLoggerService::SEVERITY_HIGH,
                    event: 'suspicious_url_access',
                    userId: $request->user()?->id,
                    context: [
                        'url' => $request->fullUrl(),
                        'path' => $path,
                        'pattern_matched' => $pattern,
                        'method' => $request->getMethod()
                    ],
                    request: $request
                );
                break;
            }
        }
    }

    /**
     * Detect malicious headers.
     */
    private function detectMaliciousHeaders(Request $request): void
    {
        $suspiciousHeaders = [
            'X-Forwarded-For' => '/[<>"\']/',           // Script injection in forwarded headers
            'User-Agent' => '/[<>"\'][^<>"\']*[<>"\']/', // Script tags in user agent
            'Referer' => '/javascript:/i',               // JavaScript URLs in referer
            'X-Real-IP' => '/[^0-9\.\:a-fA-F]/',        // Invalid IP format
        ];

        foreach ($suspiciousHeaders as $header => $pattern) {
            $value = $request->header($header);
            if ($value && preg_match($pattern, $value)) {
                SecurityLoggerService::log(
                    severity: SecurityLoggerService::SEVERITY_MEDIUM,
                    event: 'malicious_header_detected',
                    userId: $request->user()?->id,
                    context: [
                        'header' => $header,
                        'value' => substr($value, 0, 200), // Truncate for safety
                        'pattern_matched' => $pattern
                    ],
                    request: $request
                );
            }
        }
    }

    /**
     * Detect injection attempts in request parameters.
     */
    private function detectInjectionAttempts(Request $request): void
    {
        $allInputs = $request->all();
        $sqlInjectionPatterns = [
            '/union\s+select/i',
            '/select\s+.*\s+from/i',
            '/insert\s+into/i',
            '/delete\s+from/i',
            '/update\s+.*\s+set/i',
            '/drop\s+table/i',
            '/or\s+1\s*=\s*1/i',
            '/and\s+1\s*=\s*1/i',
            '/\'\s+or\s+\'/i',
        ];

        $xssPatterns = [
            '/<script[^>]*>/i',
            '/<\/script>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe[^>]*>/i',
            '/<object[^>]*>/i',
        ];

        foreach ($allInputs as $key => $value) {
            if (!is_string($value)) continue;

            // Check for SQL injection
            foreach ($sqlInjectionPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    SecurityLoggerService::log(
                        severity: SecurityLoggerService::SEVERITY_HIGH,
                        event: 'sql_injection_attempt',
                        userId: $request->user()?->id,
                        context: [
                            'parameter' => $key,
                            'value' => substr($value, 0, 100),
                            'pattern_matched' => $pattern,
                            'endpoint' => $request->getPathInfo()
                        ],
                        request: $request
                    );
                    break;
                }
            }

            // Check for XSS
            foreach ($xssPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    SecurityLoggerService::log(
                        severity: SecurityLoggerService::SEVERITY_HIGH,
                        event: 'xss_attempt',
                        userId: $request->user()?->id,
                        context: [
                            'parameter' => $key,
                            'value' => substr($value, 0, 100),
                            'pattern_matched' => $pattern,
                            'endpoint' => $request->getPathInfo()
                        ],
                        request: $request
                    );
                    break;
                }
            }
        }
    }

    /**
     * Log authentication errors.
     */
    private function logAuthenticationErrors(Request $request, SymfonyResponse $response): void
    {
        if ($response->getStatusCode() === 401) {
            SecurityLoggerService::log(
                severity: SecurityLoggerService::SEVERITY_MEDIUM,
                event: 'unauthorized_access_attempt',
                userId: $request->user()?->id,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'has_auth_header' => $request->hasHeader('Authorization'),
                    'jwt_present' => $request->bearerToken() !== null
                ],
                request: $request
            );
        }

        if ($response->getStatusCode() === 403) {
            SecurityLoggerService::log(
                severity: SecurityLoggerService::SEVERITY_MEDIUM,
                event: 'forbidden_access_attempt',
                userId: $request->user()?->id,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'user_authenticated' => $request->user() !== null
                ],
                request: $request
            );
        }
    }

    /**
     * Log CORS errors.
     */
    private function logCorsErrors(Request $request, SymfonyResponse $response): void
    {
        // Si es un request CORS y fue rechazado
        if ($request->headers->has('Origin') && 
            $response->getStatusCode() >= 400 && 
            $response->getStatusCode() < 500) {
            
            $origin = $request->headers->get('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);
            
            if (!in_array($origin, $allowedOrigins) && !in_array('*', $allowedOrigins)) {
                SecurityLoggerService::log(
                    severity: SecurityLoggerService::SEVERITY_LOW,
                    event: 'cors_violation',
                    userId: $request->user()?->id,
                    context: [
                        'origin' => $origin,
                        'endpoint' => $request->getPathInfo(),
                        'method' => $request->getMethod(),
                        'allowed_origins' => $allowedOrigins
                    ],
                    request: $request
                );
            }
        }
    }
}
