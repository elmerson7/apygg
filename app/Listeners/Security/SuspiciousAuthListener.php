<?php

namespace App\Listeners\Security;

use App\Services\Logging\SecurityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SuspiciousAuthListener
{
    /**
     * Handle multiple failed login attempts.
     */
    public function handleFailedLogin(Failed $event): void
    {
        $request = request();
        $ip = $request->ip();
        $email = $event->credentials['email'] ?? 'unknown';
        
        // Trackear intentos fallidos por IP
        $ipKey = "failed_logins:ip:{$ip}";
        $ipAttempts = Cache::get($ipKey, 0) + 1;
        Cache::put($ipKey, $ipAttempts, now()->addHour());
        
        // Trackear intentos fallidos por email
        $emailKey = "failed_logins:email:{$email}";
        $emailAttempts = Cache::get($emailKey, 0) + 1;
        Cache::put($emailKey, $emailAttempts, now()->addHour());
        
        // Determinar severidad
        $severity = $this->determineSeverityForFailedLogin($ipAttempts, $emailAttempts);
        
        SecurityLogger::log(
            severity: $severity,
            event: 'login_failed',
            userId: null,
            context: [
                'email' => $email,
                'ip_attempts_last_hour' => $ipAttempts,
                'email_attempts_last_hour' => $emailAttempts,
                'user_agent' => $request->userAgent(),
                'credentials_provided' => array_keys($event->credentials),
                'guard' => $event->guard
            ],
            request: $request
        );
        
        // Log actividad sospechosa si muchos intentos
        if ($ipAttempts >= 10 || $emailAttempts >= 5) {
            SecurityLogger::log(
                severity: SecurityLogger::SEVERITY_HIGH,
                event: 'suspicious_login_pattern',
                userId: null,
                context: [
                    'pattern_type' => $ipAttempts >= 10 ? 'ip_brute_force' : 'email_brute_force',
                    'attempts_count' => max($ipAttempts, $emailAttempts),
                    'target_email' => $email,
                    'source_ip' => $ip
                ],
                request: $request
            );
        }
    }

    /**
     * Handle account lockout events.
     */
    public function handleLockout(Lockout $event): void
    {
        $request = request();
        
        SecurityLogger::log(
            severity: SecurityLogger::SEVERITY_HIGH,
            event: 'account_lockout',
            userId: null,
            context: [
                'email' => $event->request->input('email'),
                'lockout_duration' => config('auth.lockout_duration', 'unknown'),
                'max_attempts' => config('auth.max_attempts', 'unknown'),
                'user_agent' => $request->userAgent()
            ],
            request: $request
        );
    }

    /**
     * Handle successful login for anomaly detection.
     */
    public function handleSuccessfulLogin(Login $event): void
    {
        $request = request();
        $user = $event->user;
        
        // Detectar login desde ubicación inusual
        $this->detectUnusualLocation($user, $request);
        
        // Detectar login en horarios inusuales
        $this->detectUnusualTiming($user, $request);
        
        // Detectar múltiples dispositivos/user agents
        $this->detectMultipleDevices($user, $request);
    }

    /**
     * Detect login from unusual location.
     */
    private function detectUnusualLocation($user, Request $request): void
    {
        $ip = $request->ip();
        $userKey = "user_ips:{$user->id}";
        $knownIps = Cache::get($userKey, []);
        
        if (!in_array($ip, $knownIps)) {
            // Nueva IP para este usuario
            $knownIps[] = $ip;
            Cache::put($userKey, array_slice($knownIps, -10), now()->addDays(30)); // Mantener últimas 10 IPs
            
            if (count($knownIps) > 1) { // No es el primer login
                SecurityLogger::log(
                    severity: SecurityLogger::SEVERITY_MEDIUM,
                    event: 'login_from_new_location',
                    userId: $user->id,
                    context: [
                        'new_ip' => $ip,
                        'known_ips_count' => count($knownIps) - 1,
                        'user_agent' => $request->userAgent()
                    ],
                    request: $request
                );
            }
        }
    }

    /**
     * Detect login at unusual times.
     */
    private function detectUnusualTiming($user, Request $request): void
    {
        $hour = now()->hour;
        $userKey = "user_login_hours:{$user->id}";
        $loginHours = Cache::get($userKey, []);
        
        $loginHours[] = $hour;
        $loginHours = array_slice($loginHours, -50); // Mantener últimas 50 horas de login
        Cache::put($userKey, $loginHours, now()->addDays(30));
        
        // Si es fuera del horario habitual (muy temprano o muy tarde)
        if (count($loginHours) > 10) {
            $averageHour = array_sum($loginHours) / count($loginHours);
            $deviation = abs($hour - $averageHour);
            
            if ($deviation > 6) { // Más de 6 horas de diferencia del promedio
                SecurityLogger::log(
                    severity: SecurityLogger::SEVERITY_LOW,
                    event: 'login_unusual_time',
                    userId: $user->id,
                    context: [
                        'login_hour' => $hour,
                        'average_hour' => round($averageHour, 1),
                        'deviation_hours' => round($deviation, 1),
                        'total_logins_analyzed' => count($loginHours)
                    ],
                    request: $request
                );
            }
        }
    }

    /**
     * Detect multiple devices/user agents.
     */
    private function detectMultipleDevices($user, Request $request): void
    {
        $userAgent = $request->userAgent();
        $userKey = "user_agents:{$user->id}";
        $knownAgents = Cache::get($userKey, []);
        
        $agentHash = md5($userAgent);
        if (!in_array($agentHash, $knownAgents)) {
            $knownAgents[] = $agentHash;
            $knownAgents = array_slice($knownAgents, -5); // Mantener últimos 5 user agents
            Cache::put($userKey, $knownAgents, now()->addDays(30));
            
            if (count($knownAgents) > 3) { // Más de 3 dispositivos diferentes
                SecurityLogger::log(
                    severity: SecurityLogger::SEVERITY_LOW,
                    event: 'login_multiple_devices',
                    userId: $user->id,
                    context: [
                        'new_user_agent' => substr($userAgent, 0, 200),
                        'known_devices_count' => count($knownAgents),
                        'user_agent_hash' => $agentHash
                    ],
                    request: $request
                );
            }
        }
    }

    /**
     * Determine severity for failed login attempts.
     */
    private function determineSeverityForFailedLogin(int $ipAttempts, int $emailAttempts): string
    {
        if ($ipAttempts >= 20 || $emailAttempts >= 10) {
            return SecurityLogger::SEVERITY_CRITICAL;
        } elseif ($ipAttempts >= 10 || $emailAttempts >= 5) {
            return SecurityLogger::SEVERITY_HIGH;
        } elseif ($ipAttempts >= 5 || $emailAttempts >= 3) {
            return SecurityLogger::SEVERITY_MEDIUM;
        } else {
            return SecurityLogger::SEVERITY_LOW;
        }
    }
}
