<?php

namespace App\Observers;

use App\Models\ApiKey;
use App\Services\CacheService;

/**
 * ApiKeyObserver
 *
 * Observer para invalidar cache automáticamente cuando cambian API Keys.
 */
class ApiKeyObserver
{
    /**
     * Handle the ApiKey "created" event.
     */
    public function created(ApiKey $apiKey): void
    {
        $this->invalidateCache($apiKey);
    }

    /**
     * Handle the ApiKey "updated" event.
     */
    public function updated(ApiKey $apiKey): void
    {
        $this->invalidateCache($apiKey);
    }

    /**
     * Handle the ApiKey "deleted" event.
     */
    public function deleted(ApiKey $apiKey): void
    {
        $this->invalidateCache($apiKey);
    }

    /**
     * Handle the ApiKey "restored" event.
     */
    public function restored(ApiKey $apiKey): void
    {
        $this->invalidateCache($apiKey);
    }

    /**
     * Invalidar cache relacionado con la API Key
     */
    protected function invalidateCache(ApiKey $apiKey): void
    {
        // Invalidar cache de API Keys
        CacheService::forgetTag('api-keys');

        // Invalidar cache del usuario si tiene user_id
        if ($apiKey->user_id) {
            CacheService::forgetTag("user:{$apiKey->user_id}");
            CacheService::forgetTag("user:{$apiKey->user_id}:api-keys");
        }

        // Invalidar cache de validación de API Keys (patrón masivo)
        CacheService::forgetPattern('apygg:api-key:*');
    }
}
