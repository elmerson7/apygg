<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

test('no existen nombres de ruta duplicados (route:cache fallaría)', function () {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->countBy()
        ->filter(fn ($n) => $n > 1)
        ->keys()
        ->all();

    expect($names)->toBe([]);
});

test('las rutas de diagnóstico test/sentry no se registran en producción', function () {
    $this->app['env'] = 'production';

    $router = new Router($this->app['events'], $this->app);
    Route::swap($router);
    require base_path('routes/api.php');

    $uris = collect($router->getRoutes()->getRoutes())->map->uri()->all();

    expect(collect($uris)->filter(fn ($u) => str_starts_with($u, 'test/sentry')))->toBeEmpty()
        ->and($uris)->not->toBeEmpty();
});
