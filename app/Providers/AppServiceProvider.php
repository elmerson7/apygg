<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Listeners\RestoreTenantForJob;
use App\Logging\DateOrganizedStreamHandler;
use App\Logging\FilterDeprecationWarningsHandler;
use App\Models\ApiKey;
use App\Models\Webhook;
use App\Observers\ApiKeyObserver;
use App\Observers\WebhookObserver;
use App\OpenApi\ApiDocs;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
        if (class_exists(ApiKey::class)) {
            ApiKey::observe(ApiKeyObserver::class);
        }

        if (class_exists(Webhook::class)) {
            Webhook::observe(WebhookObserver::class);
        }
        // Multiempresa: restaura el tenant del job en el worker y lo limpia al terminar (docs/multi-tenancy.md)
        foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, RestoreTenantForJob::class);
        }

        // Registrar listeners para eventos de autenticación
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);

        // Acceso a la documentación de la API (Scramble).
        //
        // El acceso abierto es una decisión EXPLÍCITA (SCRAMBLE_PUBLIC_DOCS),
        // no un efecto secundario del nombre del entorno: 'dev' es una etiqueta
        // arbitraria que puede acabar en un servidor accesible desde internet, y
        // entonces la documentación quedaría expuesta sin que nadie lo decidiera.
        //
        // La segunda condición es un seguro: aunque alguien active la variable
        // en producción, no surte efecto.
        Gate::define('viewApiDocs', function ($user = null) {
            if (config('scramble.public_docs') && ! app()->isProduction()) {
                return true;
            }

            return $user && $user->isAdmin();
        });

        // Documento OpenAPI (Scramble): rutas incluidas, seguridad, envelope y errores. Ver docs/api-contract.md.
        ApiDocs::register();

        // Fallback automático de Meilisearch: si el driver es meilisearch pero
        // el contenedor no está corriendo (make up sin SEARCH=true), baja a database
        $this->fallbackScoutDriverIfMeiliUnavailable();

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
            $baseHandler = DateOrganizedStreamHandler::create([
                'filename' => 'laravel.log',
                'level' => $level,
            ]);

            // Envolver con el filtro de deprecaciones de vendor (ruido de PHP 8.5)
            $handler = new FilterDeprecationWarningsHandler($baseHandler);

            return new Logger('single', [$handler]);
        });

        // Canal daily también organizado por fecha (ya está organizado por día)
        Log::extend('daily', function ($app, $config) {
            $level = Logger::toMonologLevel($config['level'] ?? 'debug');
            $days = $config['days'] ?? 14;

            // Usar el handler organizado por fecha
            $baseHandler = DateOrganizedStreamHandler::create([
                'filename' => 'laravel.log',
                'level' => $level,
            ]);

            // Envolver con el filtro de deprecaciones de vendor (ruido de PHP 8.5)
            $handler = new FilterDeprecationWarningsHandler($baseHandler);

            return new Logger('daily', [$handler]);
        });
    }

    /**
     * Si SCOUT_DRIVER=meilisearch pero el contenedor no responde,
     * hace fallback a database para evitar errores de conexión.
     */
    protected function fallbackScoutDriverIfMeiliUnavailable(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        $host = config('scout.meilisearch.host', 'http://localhost:7700');

        try {
            $ch = curl_init($host.'/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } catch (\Throwable) {
            $httpCode = 0;
        }

        if ($httpCode !== 200) {
            config(['scout.driver' => 'database']);
            Log::debug('Scout driver fallback: meilisearch unavailable, using database');
        }
    }
}
