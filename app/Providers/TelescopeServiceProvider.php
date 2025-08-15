<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

	$isLocal = $this->app->environment('local', 'dev', 'staging');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if ($entry->type === 'request') {
                $uri = $entry->content['uri'] ?? '';
                $ip = $entry->content['ip_address'] ?? '';
                // Filtrar solo las requests a /up, pero solo ignorar la IP 127.0.0.1 si la request es a /up
                if (
                    $uri === 'up' ||
                    $uri === '/up' ||
                    str_starts_with($uri, '/up')
                ) {
                    // Si la IP es 127.0.0.1 y la URI es /up, no registrar
                    if ($ip === '127.0.0.1') {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user = null) {
		return in_array(app()->environment(), ['local', 'dev', 'staging']);
        });
    }
}
