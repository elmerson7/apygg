<?php

namespace App\Http\Middleware;

use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission Middleware
 *
 * Middleware para verificar que el usuario autenticado tenga un permiso específico.
 * Se puede usar en rutas para proteger endpoints según permisos RBAC.
 *
 * Uso en rutas:
 * Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
 * Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
 *
 * Auto-mapeo: si el permiso no contiene punto (ej. "users"), se completa automáticamente
 * según el método HTTP: GET → users.read, POST → users.create, PUT/PATCH → users.update, DELETE → users.delete
 */
class CheckPermission
{
    private const METHOD_MAP = [
        'GET' => 'read',
        'HEAD' => 'read',
        'POST' => 'create',
        'PUT' => 'update',
        'PATCH' => 'update',
        'DELETE' => 'delete',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  string  ...$permissions  Permisos requeridos (puede ser múltiples)
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
                'error' => [
                    'type' => 'authentication_required',
                    'code' => 'UNAUTHENTICATED',
                ],
            ], 401);
        }

        $user = auth()->user();

        if (empty($permissions)) {
            return $next($request);
        }

        $resolvedPermissions = [];
        foreach ($permissions as $permission) {
            if (str_contains($permission, '.')) {
                $resolvedPermissions[] = $permission;
            } else {
                $action = self::METHOD_MAP[$request->method()] ?? 'read';
                $resolvedPermissions[] = $permission.'.'.$action;
            }
        }

        $hasPermission = false;
        $checkedPermissions = [];

        foreach ($resolvedPermissions as $permission) {
            $checkedPermissions[] = $permission;
            if ($user->hasPermission($permission)) {
                $hasPermission = true;
                break;
            }
        }

        if (! $hasPermission) {
            // Registrar intento de acceso denegado
            SecurityLogger::logPermissionDenied(
                $user,
                implode('|', $checkedPermissions),
                $request->path(),
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para realizar esta acción',
                'error' => [
                    'type' => 'permission_denied',
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'required_permissions' => $checkedPermissions,
                ],
            ], 403);
        }

        return $next($request);
    }
}
