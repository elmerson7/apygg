<?php

namespace App\Listeners;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Foundation\Application;

/**
 * Listener de Octane (RequestReceived/TaskReceived/TickReceived): cinturón y tirantes. El
 * `TenantContext` es `scoped` y ya se recrea por operación; esto garantiza que nada del
 * tenant anterior sobrevive aunque el binding cambiara.
 */
class ResetTenantContext
{
    public function handle(object $event): void
    {
        $app = property_exists($event, 'sandbox') && $event->sandbox instanceof Application ? $event->sandbox : app();

        if ($app->resolved(TenantContext::class)) {
            $app->make(TenantContext::class)->clear();
        }
    }
}
