<?php

// TEMPORALMENTE: Solo AppServiceProvider para diagnosticar el problema
return [
    App\Providers\AppServiceProvider::class,
    // Deshabilitados temporalmente para diagnosticar
    // App\Providers\TelescopeServiceProvider::class,
    // App\Providers\HorizonServiceProvider::class,
    // App\Providers\CorsValidationServiceProvider::class,
    // \Sentry\Laravel\ServiceProvider::class,
];
