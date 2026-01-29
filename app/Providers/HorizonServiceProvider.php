<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // En desarrollo local y dev, permitir acceso sin autenticación
            if (app()->environment('local', 'dev')) {
                return true;
            }

            // En staging y producción, solo administradores
            if (! $user) {
                return false;
            }

            // Verificar si el usuario tiene rol admin o permiso específico
            if (method_exists($user, 'isAdmin')) {
                return $user->isAdmin();
            }

            // Verificar si el usuario tiene permiso específico
            if (method_exists($user, 'hasPermission')) {
                return $user->hasPermission('horizon.view');
            }

            // Lista de emails permitidos (fallback)
            return in_array($user->email, [
                config('horizon.admin_email', config('mail.from.address')),
            ]);
        });
    }
}
