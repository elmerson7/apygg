<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckApiKeyScope Middleware
 *
 * Middleware para verificar que la API Key tenga los scopes requeridos.
 * Solo funciona si la autenticación fue con API Key (no JWT).
 *
 * Uso en rutas:
 * Route::get('/users', [Controller::class, 'method'])->middleware('api-key-scope:users.read');
 * Route::post('/users', [Controller::class, 'method'])->middleware('api-key-scope:users.write,users.create');
 */
class CheckApiKeyScope
{
    /**
     * Handle an incoming request.
     *
     * @param  string  ...$scopes  Scopes requeridos (puede ser múltiples)
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        // Verificar que la autenticación fue con API Key
        if ($request->attributes->get('authenticated_via') !== 'api-key') {
            // Si no fue con API Key, permitir acceso (puede ser JWT con permisos RBAC)
            return $next($request);
        }

        // Obtener API Key ID del request
        $apiKeyId = $request->attributes->get('api_key_id');

        if (! $apiKeyId) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no encontrada en el request',
                'error' => [
                    'type' => 'api_key_missing',
                    'code' => 'API_KEY_NOT_FOUND',
                ],
            ], 401);
        }

        // Obtener API Key
        $apiKey = ApiKey::find($apiKeyId);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no encontrada',
                'error' => [
                    'type' => 'api_key_not_found',
                    'code' => 'API_KEY_NOT_FOUND',
                ],
            ], 401);
        }

        // Si no se especifican scopes, permitir acceso
        if (empty($scopes)) {
            return $next($request);
        }

        // Verificar si la key tiene alguno de los scopes requeridos
        // Si se pasan múltiples scopes, la key necesita tener al menos uno
        $hasScope = false;
        $checkedScopes = [];

        foreach ($scopes as $scope) {
            $checkedScopes[] = $scope;
            if ($apiKey->hasScope($scope)) {
                $hasScope = true;
                break;
            }
        }

        if (! $hasScope) {
            // Registrar intento de acceso denegado por falta de scope
            $user = $apiKey->user;
            SecurityLogger::logPermissionDenied(
                $user instanceof \App\Models\User ? $user : null,
                'api_key_scope_required: '.implode('|', $checkedScopes),
                $request->path(),
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'La API Key no tiene los scopes necesarios para realizar esta acción',
                'error' => [
                    'type' => 'scope_denied',
                    'code' => 'INSUFFICIENT_SCOPES',
                    'required_scopes' => $checkedScopes,
                    'api_key_scopes' => $apiKey->scopes ?? [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
