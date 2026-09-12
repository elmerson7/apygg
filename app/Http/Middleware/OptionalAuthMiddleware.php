<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * OptionalAuthMiddleware
 *
 * Autenticación opcional: resuelve el usuario desde el Bearer token del request
 * actual (si existe y es válido) y lo deja en el atributo `auth_user` del
 * request. Si no hay token, o es inválido, continúa como anónimo sin lanzar 401.
 *
 * Útil en endpoints que sirven tanto a invitados como a usuarios autenticados y
 * cambian su comportamiento según el contexto (checkout público vs. panel).
 *
 * IMPORTANTE — por qué no basta con `auth('api')->user()`:
 * bajo Octane/FrankenPHP el guard JWT es un singleton que sobrevive entre
 * requests y cachea el último usuario resuelto. Sin limpiar esa caché, un
 * request anónimo puede "heredar" el usuario del request anterior. Por eso se
 * resetea la propiedad `user` del guard antes de resolver.
 *
 * Uso en rutas:
 *   Route::post('/payments', [PaymentController::class, 'store'])
 *       ->middleware('auth.optional');
 *
 * Lectura en el controlador:
 *   $user = $request->attributes->get('auth_user');
 */
class OptionalAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        $user = null;

        if ($request->bearerToken()) {
            try {
                // Limpiar la caché de usuario del singleton: el guard la reutiliza
                // entre requests bajo Octane y puede traer el usuario anterior.
                $reflection = new \ReflectionProperty($guard, 'user');
                $reflection->setValue($guard, null);

                $guard->setRequest($request);
                $guard->setToken($request->bearerToken());
                $user = $guard->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}
