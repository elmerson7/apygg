<?php

namespace App\Observers;

use App\Models\Webhook;
use App\Services\CacheService;

/**
 * WebhookObserver
 *
 * Observer para invalidar cache automáticamente cuando cambian Webhooks.
 */
class WebhookObserver
{
    /**
     * Handle the Webhook "created" event.
     */
    public function created(Webhook $webhook): void
    {
        $this->invalidateCache($webhook);
    }

    /**
     * Handle the Webhook "updated" event.
     */
    public function updated(Webhook $webhook): void
    {
        $this->invalidateCache($webhook);
    }

    /**
     * Handle the Webhook "deleted" event.
     */
    public function deleted(Webhook $webhook): void
    {
        $this->invalidateCache($webhook);
    }

    /**
     * Handle the Webhook "restored" event.
     */
    public function restored(Webhook $webhook): void
    {
        $this->invalidateCache($webhook);
    }

    /**
     * Invalidar cache relacionado con el Webhook
     */
    protected function invalidateCache(Webhook $webhook): void
    {
        // Invalidar cache de Webhooks
        CacheService::forgetTag('webhooks');

        // Invalidar cache del usuario si tiene user_id
        if ($webhook->user_id) {
            CacheService::forgetTag("user:{$webhook->user_id}");
            CacheService::forgetTag("user:{$webhook->user_id}:webhooks");
        }

        // Invalidar cache específico de webhook
        CacheService::forget("webhook:{$webhook->id}");

        // Invalidar cache de webhooks activos (usado en warming)
        CacheService::forgetPattern('apygg:webhook:*');
    }
}
