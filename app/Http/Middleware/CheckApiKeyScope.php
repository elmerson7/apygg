<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckApiKeyScope Middleware
 *
 * Middleware para verificar que la API Key tenga los scopes requeridos.
 * FALLA EN CERRADO: solo deja pasar una petición autenticada con API Key (guard `api-key`)
 * cuya key tenga al menos uno de los scopes. Sin API Key responde 401; una key sin scopes
 * no tiene acceso (el acceso total es el scope explícito `*`).
 *
 * Uso en rutas (siempre tras `auth:api-key`):
 * Route::get('/users', [Controller::class, 'method'])->middleware(['auth:api-key', 'api-key-scope:users.read']);
 * Route::post('/users', [Controller::class, 'method'])->middleware(['auth:api-key', 'api-key-scope:users.write,users.create']);
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
            return response()->json([
                'success' => false,
                'message' => 'Esta ruta requiere una API Key',
                'code' => ErrorCode::ApiKeyRequired->value,
                'error' => [
                    'type' => 'api_key_missing',
                    'code' => 'API_KEY_REQUIRED',
                ],
            ], 401);
        }

        // Obtener API Key ID del request
        $apiKeyId = $request->attributes->get('api_key_id');

        if (! $apiKeyId) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no encontrada en el request',
                'code' => ErrorCode::ApiKeyInvalid->value,
                'error' => [
                    'type' => 'api_key_missing',
                    'code' => 'API_KEY_NOT_FOUND',
                ],
            ], 401);
        }

        // Obtener API Key (una revocada entre medias ya no existe)
        $apiKey = ApiKey::find($apiKeyId);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no encontrada',
                'code' => ErrorCode::ApiKeyInvalid->value,
                'error' => [
                    'type' => 'api_key_not_found',
                    'code' => 'API_KEY_NOT_FOUND',
                ],
            ], 401);
        }

        // Sin scopes declarados en la ruta basta con una API Key válida
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
                $user instanceof User ? $user : null,
                'api_key_scope_required: '.implode('|', $checkedScopes),
                $request->path(),
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'La API Key no tiene los scopes necesarios para realizar esta acción',
                'code' => ErrorCode::InsufficientScope->value,
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
