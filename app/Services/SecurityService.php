<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SecurityService
 * 
 * Servicio centralizado para operaciones de seguridad:
 * encriptación, hashing, validación de IPs, detección de comportamiento sospechoso.
 * 
 * @package App\Services
 */
class SecurityService
{
    /**
     * Encriptar datos sensibles
     *
     * @param mixed $value Valor a encriptar
     * @return string Valor encriptado
     */
    public static function encrypt($value): string
    {
        return Crypt::encryptString($value);
    }

    /**
     * Desencriptar datos
     *
     * @param string $encryptedValue Valor encriptado
     * @return mixed Valor desencriptado
     */
    public static function decrypt(string $encryptedValue)
    {
        try {
            return Crypt::decryptString($encryptedValue);
        } catch (\Exception $e) {
            Log::warning('Failed to decrypt value', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Hash de contraseña usando bcrypt
     *
     * @param string $password Contraseña en texto plano
     * @param int $rounds Número de rounds (default: 10)
     * @return string Hash de contraseña
     */
    public static function hashPassword(string $password, int $rounds = 10): string
    {
        return Hash::make($password, ['rounds' => $rounds]);
    }

    /**
     * Verificar contraseña
     *
     * @param string $password Contraseña en texto plano
     * @param string $hash Hash almacenado
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }

    /**
     * Verificar si IP está en whitelist
     *
     * @param string $ip IP a verificar
     * @param array|null $whitelist Lista de IPs permitidas (null = usar config)
     * @return bool
     */
    public static function isIpWhitelisted(string $ip, ?array $whitelist = null): bool
    {
        $whitelist = $whitelist ?? config('security.ip_whitelist', []);

        if (empty($whitelist)) {
            return true; // Si no hay whitelist, todas las IPs están permitidas
        }

        foreach ($whitelist as $allowedIp) {
            // Soporte para rangos CIDR
            if (str_contains($allowedIp, '/')) {
                if (self::ipInRange($ip, $allowedIp)) {
                    return true;
                }
            } elseif ($ip === $allowedIp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si IP está en rango CIDR
     *
     * @param string $ip
     * @param string $range Rango CIDR (ej: 192.168.1.0/24)
     * @return bool
     */
    protected static function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Detectar comportamiento sospechoso
     *
     * @param string $ip IP del usuario
     * @param string $action Acción realizada
     * @param array $context Contexto adicional
     * @return array ['is_suspicious' => bool, 'reasons' => array]
     */
    public static function detectSuspiciousBehavior(string $ip, string $action, array $context = []): array
    {
        $reasons = [];
        $isSuspicious = false;

        // Verificar rate limiting por IP
        $rateLimitKey = "security:rate_limit:{$ip}:{$action}";
        $attempts = cache()->get($rateLimitKey, 0);
        $maxAttempts = config('security.max_attempts_per_action', 10);
        $timeWindow = config('security.rate_limit_window', 300); // 5 minutos

        if ($attempts >= $maxAttempts) {
            $reasons[] = "Rate limit exceeded: {$attempts} attempts in {$timeWindow} seconds";
            $isSuspicious = true;
        } else {
            cache()->put($rateLimitKey, $attempts + 1, $timeWindow);
        }

        // Verificar patrones sospechosos
        if (self::hasSuspiciousPatterns($action, $context)) {
            $reasons[] = 'Suspicious patterns detected';
            $isSuspicious = true;
        }

        // Verificar IP en blacklist
        if (self::isIpBlacklisted($ip)) {
            $reasons[] = 'IP is blacklisted';
            $isSuspicious = true;
        }

        // Verificar múltiples acciones fallidas
        $failedActionsKey = "security:failed_actions:{$ip}";
        $failedActions = cache()->get($failedActionsKey, []);
        
        if (count($failedActions) >= config('security.max_failed_actions', 5)) {
            $reasons[] = 'Multiple failed actions detected';
            $isSuspicious = true;
        }

        return [
            'is_suspicious' => $isSuspicious,
            'reasons' => $reasons,
            'risk_score' => self::calculateRiskScore($reasons),
        ];
    }

    /**
     * Detectar patrones sospechosos
     */
    protected static function hasSuspiciousPatterns(string $action, array $context): bool
    {
        // Detectar intentos de SQL injection
        $suspiciousPatterns = [
            '/\b(union|select|insert|update|delete|drop|exec|script)\b/i',
            '/[<>"\']/',
            '/javascript:/i',
            '/on\w+\s*=/i',
        ];

        $dataToCheck = json_encode($context);

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $dataToCheck)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si IP está en blacklist
     */
    protected static function isIpBlacklisted(string $ip): bool
    {
        $blacklist = config('security.ip_blacklist', []);
        return in_array($ip, $blacklist);
    }

    /**
     * Calcular score de riesgo
     */
    protected static function calculateRiskScore(array $reasons): int
    {
        $score = 0;
        
        foreach ($reasons as $reason) {
            if (str_contains($reason, 'blacklisted')) {
                $score += 50;
            } elseif (str_contains($reason, 'Rate limit')) {
                $score += 30;
            } elseif (str_contains($reason, 'patterns')) {
                $score += 40;
            } elseif (str_contains($reason, 'failed')) {
                $score += 20;
            }
        }

        return min($score, 100); // Máximo 100
    }

    /**
     * Generar token seguro
     *
     * @param int $length Longitud del token
     * @return string Token generado
     */
    public static function generateSecureToken(int $length = 64): string
    {
        return Str::random($length);
    }

    /**
     * Generar token para reset de contraseña
     *
     * @return string Token único
     */
    public static function generatePasswordResetToken(): string
    {
        return hash_hmac('sha256', Str::random(40), config('app.key'));
    }

    /**
     * Validar token de reset de contraseña
     *
     * @param string $token Token a validar
     * @param string $storedToken Token almacenado
     * @return bool
     */
    public static function validatePasswordResetToken(string $token, string $storedToken): bool
    {
        return hash_equals($storedToken, $token);
    }

    /**
     * Sanitizar entrada HTML
     *
     * @param string $input Entrada a sanitizar
     * @return string Entrada sanitizada
     */
    public static function sanitizeHtml(string $input): string
    {
        // Remover tags HTML peligrosos
        $allowedTags = '<p><br><strong><em><u><a><ul><ol><li>';
        $sanitized = strip_tags($input, $allowedTags);
        
        // Escapar atributos peligrosos
        $sanitized = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $sanitized);
        $sanitized = preg_replace('/javascript:/i', '', $sanitized);
        
        return $sanitized;
    }

    /**
     * Validar CSRF token
     *
     * @param string $token Token a validar
     * @return bool
     */
    public static function validateCsrfToken(string $token): bool
    {
        $sessionToken = session()->token();
        return hash_equals($sessionToken, $token);
    }

    /**
     * Registrar acción fallida
     *
     * @param string $ip
     * @param string $action
     * @return void
     */
    public static function recordFailedAction(string $ip, string $action): void
    {
        $key = "security:failed_actions:{$ip}";
        $failedActions = cache()->get($key, []);
        
        $failedActions[] = [
            'action' => $action,
            'timestamp' => now()->toIso8601String(),
        ];

        // Mantener solo las últimas 10 acciones
        $failedActions = array_slice($failedActions, -10);
        
        cache()->put($key, $failedActions, 3600); // 1 hora
    }
}
