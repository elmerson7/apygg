<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use App\Listeners\Security\SuspiciousAuthListener;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     */
    protected $listen = [
        Failed::class => [
            [SuspiciousAuthListener::class, 'handleFailedLogin'],
        ],
        
        Lockout::class => [
            [SuspiciousAuthListener::class, 'handleLockout'],
        ],
        
        Login::class => [
            [SuspiciousAuthListener::class, 'handleSuccessfulLogin'],
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
