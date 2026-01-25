<?php

namespace App\Listeners;

use App\Services\Logging\AuthLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Log;

/**
 * LogAuthenticationEvents Listener
 *
 * Listener para registrar eventos de autenticación usando AuthLogger.
 */
class LogAuthenticationEvents
{
    /**
     * Handle login events.
     */
    public function handleLogin(Login $event): void
    {
        try {
            AuthLogger::logLoginSuccess($event->user);
        } catch (\Exception $e) {
            Log::warning('Failed to log login success', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle failed login events.
     */
    public function handleFailed(Failed $event): void
    {
        try {
            AuthLogger::logLoginFailure(
                $event->credentials['email'] ?? 'unknown',
                null,
                null,
                'Invalid credentials'
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log login failure', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle logout events.
     */
    public function handleLogout(Logout $event): void
    {
        try {
            // Usar logTokenRevoked para logout (similar a revocar token)
            AuthLogger::logTokenRevoked($event->user);
        } catch (\Exception $e) {
            Log::warning('Failed to log logout', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle password reset events.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        try {
            AuthLogger::logPasswordChanged($event->user);
        } catch (\Exception $e) {
            Log::warning('Failed to log password reset', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     */
    public function subscribe($events): void
    {
        $events->listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        $events->listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        $events->listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);
    }
}
