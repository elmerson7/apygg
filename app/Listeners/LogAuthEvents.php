<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Services\Logging\AuthLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogAuthEvents implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle UserLoggedIn event.
     */
    public function handleUserLoggedIn(UserLoggedIn $event): void
    {
        AuthLogger::logLoginSuccess(
            $event->user,
            $event->ipAddress,
            $event->userAgent
        );
    }

    /**
     * Handle UserLoggedOut event.
     */
    public function handleUserLoggedOut(UserLoggedOut $event): void
    {
        AuthLogger::logTokenRevoked(
            $event->user,
            $event->ipAddress
        );
    }
}
