<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Laravel\Horizon\HorizonServiceProvider::class,
    Laravel\Telescope\TelescopeServiceProvider::class,
    Sentry\Laravel\ServiceProvider::class,
    App\Providers\ContractServiceProvider::class,
    App\Providers\AuthSecurityServiceProvider::class,
    App\Providers\MailSystemServiceProvider::class,
    App\Providers\Filament\BackofficePanelProvider::class,
];
