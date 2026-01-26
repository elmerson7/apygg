<?php

namespace App\Listeners;

use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Services\CacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class InvalidatePermissionsCache implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(RoleAssigned|RoleRemoved|PermissionGranted|PermissionRevoked $event): void
    {
        $userId = $event->user->id;

        // Invalidar cache de permisos del usuario
        CacheService::forgetTag("user:{$userId}:permissions");
        CacheService::forgetTag("user:{$userId}:roles");
        CacheService::forgetTag("user:{$userId}");
        CacheService::forgetTag('user');

        // Invalidar cache global de permisos
        CacheService::forgetTag('permissions');
        CacheService::forgetTag('roles');
    }
}
