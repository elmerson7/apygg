<?php

namespace App\Providers;

use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Listeners\InvalidatePermissionsCache;
use App\Listeners\InvalidateUserCache;
use App\Listeners\LogAuthEvents;
use App\Listeners\LogUserActivity;
use App\Listeners\SendWelcomeEmail;
use App\Listeners\WebhookListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<string, array<int, string|array{0: class-string, 1: string}>>
     *
     * @phpstan-ignore-next-line
     */
    protected $listen = [
        // Eventos de Usuario
        UserCreated::class => [
            [LogUserActivity::class, 'handleUserCreated'],
            SendWelcomeEmail::class,
            InvalidateUserCache::class,
            WebhookListener::class,
        ],
        UserUpdated::class => [
            [LogUserActivity::class, 'handleUserUpdated'],
            InvalidateUserCache::class,
            WebhookListener::class,
        ],
        UserDeleted::class => [
            [LogUserActivity::class, 'handleUserDeleted'],
            InvalidateUserCache::class,
            WebhookListener::class,
        ],
        UserRestored::class => [
            [LogUserActivity::class, 'handleUserRestored'],
            InvalidateUserCache::class,
            WebhookListener::class,
        ],
        UserLoggedIn::class => [
            [LogAuthEvents::class, 'handleUserLoggedIn'],
            WebhookListener::class,
        ],
        UserLoggedOut::class => [
            [LogAuthEvents::class, 'handleUserLoggedOut'],
            WebhookListener::class,
        ],

        // Eventos de Autorización
        RoleAssigned::class => [
            InvalidatePermissionsCache::class,
            WebhookListener::class,
        ],
        RoleRemoved::class => [
            InvalidatePermissionsCache::class,
            WebhookListener::class,
        ],
        PermissionGranted::class => [
            InvalidatePermissionsCache::class,
            WebhookListener::class,
        ],
        PermissionRevoked::class => [
            InvalidatePermissionsCache::class,
            WebhookListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
