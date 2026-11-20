<?php

// Telescope es require-dev: la imagen de producción (composer --no-dev) no lo trae
$telescope = class_exists(Laravel\Telescope\TelescopeServiceProvider::class)
    ? [Laravel\Telescope\TelescopeServiceProvider::class]
    : [];

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Laravel\Horizon\HorizonServiceProvider::class,
    ...$telescope,
    Sentry\Laravel\ServiceProvider::class,
    App\Providers\ContractServiceProvider::class,
    App\Providers\AuthSecurityServiceProvider::class,
    App\Providers\MailSystemServiceProvider::class,
    App\Providers\IntegrationsServiceProvider::class,
    App\Providers\Filament\BackofficePanelProvider::class,
];
