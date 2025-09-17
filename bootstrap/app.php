<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate as JwtAuthenticate;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken as JwtRefresh;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\TraceId;
use App\Http\Middleware\CacheControl;
use App\Http\Middleware\SecurityLogger;
use App\Http\Middleware\RateLimitLogger;
use App\Http\Middleware\WebhookSecurityLogger;


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
            'trace.id'     => TraceId::class,
            'cache.control' => CacheControl::class,
            'security.logger' => SecurityLogger::class,
            'rate.limit.logger' => RateLimitLogger::class,
            'webhook.security' => WebhookSecurityLogger::class,
        ]);
        
        // Habilitar CORS para todas las rutas
        $middleware->web(append: [
            HandleCors::class,
        ]);
        
        $middleware->api(append: [
            HandleCors::class,
            'security.logger',
            'throttle:api',
            'rate.limit.logger',
            'trace.id',
            'force.json'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($request, Throwable $e) {
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getStatusCode() : 500;
    
            // Log security-relevant exceptions
            if ($status >= 400) {
                \App\Services\Logging\SecurityLogger::logException($e, $request, $status);
            }
    
            $problem = [
                'success' => false,
                'type'   => 'https://damblix.dev/errors/'.class_basename($e),
                'title'  => $e->getMessage() ?: 'Unexpected error',
                'status' => $status,
                'detail' => method_exists($e, 'getHint') ? $e->getHint() : null,
                'instance' => method_exists($request, 'fullUrl')
                    ? $request->fullUrl()
                    : (request()?->fullUrl() ?? null),
                'meta' => [
                    'trace_id' => method_exists($request, 'attributes') && $request->attributes 
                        ? $request->attributes->get('trace_id') 
                        : (request()?->attributes?->get('trace_id') ?? null),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ];
    
            return response()->json($problem, $status, [
                'Content-Type' => 'application/problem+json'
            ]);
        });
    })
    ->create();
