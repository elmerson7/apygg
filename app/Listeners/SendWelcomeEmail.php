<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Jobs\SendWelcomeEmailJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(UserCreated $event): void
    {
        // Enviar email de bienvenida en cola
        SendWelcomeEmailJob::dispatch($event->user->id)
            ->onQueue('default');
    }
}
