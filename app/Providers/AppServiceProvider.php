<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registrar listeners para eventos de autenticación
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);
    }
}
