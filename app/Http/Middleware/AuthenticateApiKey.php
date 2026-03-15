<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Services\Logging\SecurityLogger;
use App\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthenticateApiKey Middleware
 *
 * Middleware para autenticación con API Keys.
 * Lee la key del header X-API-Key o Authorization: Bearer {key}
 * Autentica al usuario asociado y actualiza last_used_at en background.
 *
 * Uso en rutas:
 * Route::get('/endpoint', [Controller::class, 'method'])->middleware('auth:api-key');
 */
class AuthenticateApiKey
{
    protected ApiKeyService $apiKeyService;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->getApiKeyFromRequest($request);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no proporcionada',
                'error' => [
                    'type' => 'api_key_missing',
                    'code' => 'API_KEY_REQUIRED',
                ],
            ], 401);
        }

        $apiKeyModel = $this->apiKeyService->validate($apiKey);

        if (! $apiKeyModel) {
            SecurityLogger::logSuspiciousActivity(
                'Intento de acceso con API Key inválida o expirada',
                null,
                [
                    'api_key_prefix' => substr($apiKey, 0, 20).'...',
                    'ip_address' => $request->ip(),
                ],
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'API Key inválida o expirada',
                'error' => [
                    'type' => 'api_key_invalid',
                    'code' => 'INVALID_API_KEY',
                ],
            ], 401);
        }

        auth()->loginUsingId($apiKeyModel->user_id);

        $request->attributes->set('authenticated_via', 'api-key');
        $request->attributes->set('api_key_id', $apiKeyModel->id);

        $this->updateLastUsedInBackground($apiKeyModel);

        SecurityLogger::logApiKeyUsage($apiKeyModel, $request);

        return $next($request);
    }

    /**
     * Obtener API Key del request.
     * Busca en header X-API-Key o Authorization: Bearer {key}
     */
    protected function getApiKeyFromRequest(Request $request): ?string
    {
        $apiKey = $request->header('X-API-Key');

        if ($apiKey) {
            return $apiKey;
        }

        $authorization = $request->header('Authorization');

        if ($authorization && preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Actualizar last_used_at (rápido, no bloquea el request)
     */
    protected function updateLastUsedInBackground(ApiKey $apiKey): void
    {
        try {
            $apiKey->updateLastUsed();
        } catch (\Exception $e) {
            LogService::warning('Error al actualizar last_used_at de API Key', [
                'api_key_id' => $apiKey->id,
                'error' => $e->getMessage(),
            ], 'api');
        }
    }
}
