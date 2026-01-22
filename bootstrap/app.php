<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // No cargar rutas web (solo API)
        // web: __DIR__.'/../routes/web.php',
        // Cargar rutas API sin prefijo /api (directo en la raíz)
        using: function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware para API: todas las respuestas en JSON
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Forzar respuestas JSON para todas las excepciones (API-only)
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return true; // Siempre devolver JSON
        });

        // Formatear respuestas de error de forma consistente
        $exceptions->render(function (Throwable $e, $request) {
            // Manejar 404 (ruta no encontrada) de forma especial
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'message' => 'Endpoint not found.',
                    'error' => 'The requested endpoint does not exist.',
                    'path' => $request->path(),
                ], 404);
            }

            // Manejar otras excepciones HTTP
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                $statusCode = $e->getStatusCode();
                $response = [
                    'message' => $e->getMessage() ?: 'An error occurred',
                    'status' => $statusCode,
                ];

                // Solo en desarrollo: agregar detalles adicionales
                if (config('app.debug')) {
                    $response['exception'] = get_class($e);
                }

                return response()->json($response, $statusCode);
            }

            // Manejar ValidationException
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }

            // Errores del servidor (500)
            $response = [
                'message' => config('app.debug') 
                    ? $e->getMessage() 
                    : 'An internal server error occurred.',
                'status' => 500,
            ];

            // Solo en desarrollo: stack trace completo
            if (config('app.debug')) {
                $response['exception'] = get_class($e);
                $response['file'] = $e->getFile();
                $response['line'] = $e->getLine();
                $response['trace'] = collect($e->getTrace())->map(function ($trace) {
                    return Arr::except($trace, ['args']);
                })->all();
            }

            return response()->json($response, 500);
        });
    })->create();
