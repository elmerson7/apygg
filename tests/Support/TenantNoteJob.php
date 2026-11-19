<?php

namespace Tests\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Job de prueba: anota en la caché qué tenant veía y qué notas lee.
 */
class TenantNoteJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly string $resultKey) {}

    public function handle(): void
    {
        $tenantId = app(TenantContext::class)->id();

        try {
            $titles = TenantNote::query()->orderBy('id')->pluck('title')->all();
            $error = null;
        } catch (\Throwable $e) {
            $titles = [];
            $error = $e::class;
        }

        Cache::put($this->resultKey, ['tenant_id' => $tenantId, 'titles' => $titles, 'error' => $error], 60);
    }
}
