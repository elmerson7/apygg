<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Logging\DateOrganizedStreamHandler;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use Monolog\Logger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Configurar autorización de Telescope
        // Telescope::night() permite acceso sin autenticación solo en desarrollo
        Telescope::night();

        // Filtrar qué entradas se registran en Telescope
        // Solo registrar en entornos de desarrollo
        Telescope::filter(function ($entry) {
            return app()->environment(['local', 'dev']);
        });
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

        // Configurar canales de logging organizados por fecha
        $this->configureDateOrganizedLogChannels();
    }

    /**
     * Configurar canales de logging organizados por fecha (año/mes/día)
     */
    protected function configureDateOrganizedLogChannels(): void
    {
        // Canal activity
        Log::extend('activity', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $handler = DateOrganizedStreamHandler::create([
                'filename' => 'activity.log',
                'level' => $level,
            ]);

            return new Logger('activity', [$handler]);
        });

        // Canal security
        Log::extend('security', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $handler = DateOrganizedStreamHandler::create([
                'filename' => 'security.log',
                'level' => $level,
            ]);

            return new Logger('security', [$handler]);
        });

        // Canal single (laravel.log) también organizado por fecha
        Log::extend('single', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $handler = DateOrganizedStreamHandler::create([
                'filename' => 'laravel.log',
                'level' => $level,
            ]);

            return new Logger('single', [$handler]);
        });

        // Canal daily también organizado por fecha (ya está organizado por día)
        Log::extend('daily', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $days = $config['days'] ?? 14;

            // Usar el handler organizado por fecha
            $handler = DateOrganizedStreamHandler::create([
                'filename' => 'laravel.log',
                'level' => $level,
            ]);

            return new Logger('daily', [$handler]);
        });
    }
}
