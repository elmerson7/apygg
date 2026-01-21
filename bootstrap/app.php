<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', 
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS middleware - debe estar al inicio para manejar preflight requests
        $middleware->api(prepend: [
            \App\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Integración con Sentry para captura de excepciones
        if (app()->bound('sentry')) {
            $exceptions->report(function (\Throwable $e) {
                app('sentry')->captureException($e);
            });
        }
    })->create();
