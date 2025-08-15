<?php

use \Throwable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate as JwtAuthenticate;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken as JwtRefresh;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ForceJson;


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
        $middleware->throttleWithRedis();
        $middleware->alias([
            'jwt'          => JwtAuthenticate::class,
            'jwt.refresh'  => JwtRefresh::class,
            'force.json'    => ForceJson::class,
            'idempotency'  => Idempotency::class,
        ]);
        $middleware->api(append: [
            'throttle:api',
            'force.json'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($request, Throwable $e) {
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getStatusCode() : 500;
    
            $problem = [
                'type'   => 'https://damblix.dev/errors/'.class_basename($e),
                'title'  => $e->getMessage() ?: 'Unexpected error',
                'status' => $status,
                'detail' => method_exists($e, 'getHint') ? $e->getHint() : null,
                'instance' => method_exists($request, 'fullUrl')
                    ? $request->fullUrl()
                    : (request()?->fullUrl() ?? null),
            ];
    
            return response()->json($problem, $status, [
                'Content-Type' => 'application/problem+json'
            ]);
        });
    })
    ->create();
