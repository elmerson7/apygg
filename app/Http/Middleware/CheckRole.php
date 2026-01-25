<?php

namespace App\Http\Middleware;

use App\Services\LogService;
use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckRole Middleware
 *
 * Middleware para verificar que el usuario autenticado tenga un rol específico.
 * Se puede usar en rutas para proteger endpoints según roles RBAC.
 *
 * Uso en rutas:
 * Route::get('/admin/users', [AdminController::class, 'index'])->middleware('role:admin');
 * Route::get('/moderator/posts', [ModeratorController::class, 'index'])->middleware('role:admin|moderator');
 *
 * @package App\Http\Middleware
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string ...$roles Roles requeridos (puede ser múltiples)
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
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

        // Si no se especifican roles, permitir acceso (útil para solo verificar autenticación)
        if (empty($roles)) {
            return $next($request);
        }

        // Verificar si el usuario tiene alguno de los roles requeridos
        // Si se pasan múltiples roles, el usuario necesita tener al menos uno
        $hasRole = false;
        $checkedRoles = [];

        foreach ($roles as $role) {
            $checkedRoles[] = $role;
            if ($user->hasRole($role)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            // Registrar intento de acceso denegado
            SecurityLogger::logPermissionDenied(
                $user,
                'role_required',
                $request->path(),
                $request
            );

            LogService::warning('Acceso denegado por falta de rol', [
                'user_id' => $user->id,
                'required_roles' => $checkedRoles,
                'user_roles' => $user->roles->pluck('name')->toArray(),
                'path' => $request->path(),
            ], 'security');

            return response()->json([
                'success' => false,
                'message' => 'No tienes el rol necesario para realizar esta acción',
                'error' => [
                    'type' => 'role_denied',
                    'code' => 'INSUFFICIENT_ROLES',
                    'required_roles' => $checkedRoles,
                ],
            ], 403);
        }

        return $next($request);
    }
}
