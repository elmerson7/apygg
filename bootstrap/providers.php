<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Laravel\Horizon\HorizonServiceProvider::class,
    Laravel\Telescope\TelescopeServiceProvider::class,
    Sentry\Laravel\ServiceProvider::class,
];
