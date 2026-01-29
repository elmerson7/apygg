<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Logging\DateOrganizedStreamHandler;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        // Registrar Observers para invalidación automática de cache
        if (class_exists(\App\Models\ApiKey::class)) {
            \App\Models\ApiKey::observe(\App\Observers\ApiKeyObserver::class);
        }

        if (class_exists(\App\Models\Webhook::class)) {
            \App\Models\Webhook::observe(\App\Observers\WebhookObserver::class);
        }
        // Registrar listeners para eventos de autenticación
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);

        // Configurar Gate para acceso a documentación de Scramble
        // Permite acceso en entornos de desarrollo (local, dev) sin autenticación
        // En producción, requiere autenticación y permisos específicos
        Gate::define('viewApiDocs', function ($user = null) {
            // En entornos de desarrollo, permitir acceso sin autenticación
            if (app()->environment(['local', 'dev'])) {
                return true;
            }

            // En producción, requerir autenticación y permisos de administrador
            if ($user && $user->isAdmin()) {
                return true;
            }

            return false;
        });

        // Configurar Scramble para detectar todas las rutas de la API
        // Las rutas están en la raíz (sin prefijo /api), por lo que necesitamos un resolver personalizado
        Scramble::afterOpenApiGenerated(function ($openApi) {
            // Esta función se ejecuta después de generar el OpenAPI
            // Podemos modificar el documento aquí si es necesario
        });

        // Configurar resolver de rutas personalizado para Scramble
        // Incluye todas las rutas de la API excepto las de documentación, health checks básicos y herramientas de desarrollo
        Scramble::routes(function (Route $route) {
            $uri = $route->uri();

            // Excluir rutas de documentación de Scramble
            if (str_starts_with($uri, 'docs')) {
                return false;
            }

            // Excluir rutas de herramientas de desarrollo (Telescope, Horizon)
            if (str_starts_with($uri, 'telescope') || str_starts_with($uri, 'horizon')) {
                return false;
            }

            // Excluir health checks básicos (solo incluir /health/detailed si está autenticado)
            if ($uri === 'health' || $uri === 'health/live' || $uri === 'health/ready') {
                return false;
            }

            // Excluir rutas de prueba/test
            if (str_starts_with($uri, 'test')) {
                return false;
            }

            // Incluir todas las demás rutas (auth, users, api-keys, health/detailed, etc.)
            return true;
        });

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

        // Canal auth
        Log::extend('auth', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $handler = DateOrganizedStreamHandler::create([
                'filename' => 'auth.log',
                'level' => $level,
            ]);

            return new Logger('auth', [$handler]);
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
