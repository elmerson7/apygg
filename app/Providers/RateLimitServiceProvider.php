<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Límite general API: 120/min y 10/seg por token/IP
        RateLimiter::for('api', function (Request $request) {
            $id = optional($request->user())->id ?: $request->ip();
            return [
                Limit::perMinute(120)->by($id),
                Limit::perSecond(10)->by($id),
            ];
        });

        // Más estrictos para auth y swipes
        RateLimiter::for('auth', fn (Request $r) => [Limit::perMinute(10)->by($r->ip())]);
        RateLimiter::for('matches', fn (Request $r) => [Limit::perSecond(5)->by($r->user()?->id ?? $r->ip())]);
        RateLimiter::for('users', fn (Request $r) => [Limit::perMinute(60)->by($r->user()?->id ?? $r->ip())]);
        RateLimiter::for('uploads', fn (Request $r) => [Limit::perMinute(20)->by($r->user()?->id ?? $r->ip())]);
        
        // Rate limiters para archivos
        RateLimiter::for('files', fn (Request $r) => [Limit::perMinute(100)->by($r->user()?->id ?? $r->ip())]);
        RateLimiter::for('public-files', fn (Request $r) => [Limit::perMinute(30)->by($r->ip())]);
    }
}
