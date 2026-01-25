<?php

namespace App\Listeners;

use App\Infrastructure\Logging\Loggers\AuthLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Log;

/**
 * LogAuthenticationEvents Listener
 *
 * Listener para registrar eventos de autenticación usando AuthLogger.
 *
 * @package App\Listeners
 */
class LogAuthenticationEvents
{
    /**
     * Handle login events.
     *
     * @param Login $event
     * @return void
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
     *
     * @param Failed $event
     * @return void
     */
    public function handleFailed(Failed $event): void
    {
        try {
            AuthLogger::logLoginFailure(
                $event->credentials['email'] ?? null,
                $event->user,
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
     *
     * @param Logout $event
     * @return void
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
     *
     * @param PasswordReset $event
     * @return void
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
     * @param \Illuminate\Events\Dispatcher $events
     * @return void
     */
    public function subscribe($events): void
    {
        $events->listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        $events->listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        $events->listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);
    }
}
