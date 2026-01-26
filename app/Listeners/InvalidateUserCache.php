<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Services\CacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class InvalidateUserCache implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(UserCreated|UserUpdated|UserDeleted|UserRestored $event): void
    {
        $userId = $event->user->id;

        // Invalidar cache específico del usuario
        CacheService::forgetTag("user:{$userId}");
        CacheService::forgetTag('user');

        // Invalidar cache de listados de usuarios
        CacheService::forgetTag('users');

        // Invalidar cache de permisos del usuario (si cambió)
        if ($event instanceof UserUpdated) {
            CacheService::forgetTag("user:{$userId}:permissions");
            CacheService::forgetTag("user:{$userId}:roles");
        }
    }
}
