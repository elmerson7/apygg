<?php

namespace App\Http\Middleware;

use App\Services\LogService;
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
 * @package App\Http\Middleware
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string ...$permissions Permisos requeridos (puede ser múltiples)
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        // Verificar que el usuario esté autenticado
        if (!auth()->check()) {
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

        // Si no se especifican permisos, permitir acceso (útil para solo verificar autenticación)
        if (empty($permissions)) {
            return $next($request);
        }

        // Verificar si el usuario tiene alguno de los permisos requeridos
        // Si se pasan múltiples permisos, el usuario necesita tener al menos uno
        $hasPermission = false;
        $checkedPermissions = [];

        foreach ($permissions as $permission) {
            $checkedPermissions[] = $permission;
            if ($user->hasPermission($permission)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
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
