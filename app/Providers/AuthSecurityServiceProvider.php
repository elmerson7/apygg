<?php

namespace App\Providers;

use App\Auth\ApiJwtGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Guard JWT propio y política de contraseñas por defecto.
 */
class AuthSecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sustituye el driver 'jwt' del paquete (que se registra antes que los
        // providers de la app) por uno que rechaza refresh tokens y sesiones
        // revocadas.
        $this->app->make('auth')->extend('jwt', function ($app, $name, array $config) {
            $guard = new ApiJwtGuard(
                $app['tymon.jwt'],
                $app['auth']->createUserProvider($config['provider']),
                $app['request'],
                $app['events']
            );

            $guard->setTTL(Arr::get($config, 'ttl', $app['config']->get('jwt.ttl')));

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        // Misma política en registro, cambio y reset de contraseña.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());
    }
}
