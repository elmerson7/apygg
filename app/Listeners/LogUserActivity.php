<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Services\Logging\ActivityLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogUserActivity implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle UserCreated event.
     */
    public function handleUserCreated(UserCreated $event): void
    {
        ActivityLogger::log($event->user, 'created', null);
    }

    /**
     * Handle UserUpdated event.
     */
    public function handleUserUpdated(UserUpdated $event): void
    {
        $oldValues = ! empty($event->oldAttributes) ? $event->oldAttributes : null;
        ActivityLogger::log($event->user, 'updated', $oldValues);
    }

    /**
     * Handle UserDeleted event.
     */
    public function handleUserDeleted(UserDeleted $event): void
    {
        ActivityLogger::log($event->user, 'deleted', null);
    }

    /**
     * Handle UserRestored event.
     */
    public function handleUserRestored(UserRestored $event): void
    {
        ActivityLogger::log($event->user, 'restored', null);
    }
}
