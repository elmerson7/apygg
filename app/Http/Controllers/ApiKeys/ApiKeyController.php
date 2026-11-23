<?php

namespace App\Http\Controllers\ApiKeys;

use App\Contracts\ApiKeyServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKeys\RotateApiKeyRequest;
use App\Http\Requests\ApiKeys\StoreApiKeyRequest;
use App\Http\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ApiKeyController
 *
 * Gestión de API Keys del usuario autenticado; la lógica vive en ApiKeyServiceInterface.
 */
class ApiKeyController extends Controller
{
    protected ?string $resource = ApiKeyResource::class;

    public function __construct(protected ApiKeyServiceInterface $apiKeyService) {}

    /**
     * Listado de las API Keys del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->sendPaginated(
            $this->apiKeyService->list($this->authUser($request), $this->perPage($request, 20))
        );
    }

    /**
     * Crear una API Key. La key completa solo se devuelve en esta respuesta.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->apiKeyService->create(
            $this->authUser($request),
            $validated['name'],
            $validated['scopes'] ?? [],
            isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            $validated['environment'] ?? 'live',
        );

        return $this->sendSuccess([
            'api_key' => new ApiKeyResource($result['api_key']),
            'key' => $result['key'], // Solo se retorna una vez
            'warning' => 'Guarda esta key de forma segura. No se volverá a mostrar.',
        ], 'API Key creada exitosamente', 201);
    }

    /**
     * Detalle de una API Key propia.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->sendSuccess($this->ownedKey($request, $id), 'API Key obtenida exitosamente');
    }

    /**
     * Revocar una API Key propia.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->ownedKey($request, $id);

        $this->apiKeyService->revoke($id);

        return $this->sendSuccess(null, 'API Key revocada exitosamente');
    }

    /**
     * Rotar una API Key (crea una nueva y caduca la anterior tras el periodo de gracia).
     */
    public function rotate(RotateApiKeyRequest $request, string $id): JsonResponse
    {
        $this->ownedKey($request, $id);

        $validated = $request->validated();

        $result = $this->apiKeyService->rotate(
            $id,
            $validated['name'] ?? null,
            $validated['scopes'] ?? null,
            $validated['environment'] ?? 'live',
        );

        return $this->sendSuccess([
            'api_key' => new ApiKeyResource($result['api_key']),
            'key' => $result['key'], // Solo se retorna una vez
            'old_key_expires_at' => $result['old_key_expires_at'],
            'warning' => 'Guarda esta key de forma segura. No se volverá a mostrar. La key antigua expirará en '.$result['old_key_expires_at'],
        ], 'API Key rotada exitosamente', 201);
    }

    /**
     * Quién es la API Key que llama (ruta de ejemplo del mecanismo `auth:api-key` + `api-key-scope:`).
     *
     * GET /api-key/whoami
     */
    public function whoami(Request $request): JsonResponse
    {
        $apiKey = ApiKey::findOrFail((int) $request->attributes->get('api_key_id'));

        $keyUser = $request->user('api-key');
        abort_unless($keyUser instanceof User, 401);

        return $this->sendSuccess([
            'api_key' => ['id' => $apiKey->id, 'name' => $apiKey->name, 'scopes' => $apiKey->scopes ?? []],
            'user' => ['id' => $keyUser->id, 'name' => $keyUser->name],
        ], 'API Key autenticada');
    }

    /**
     * Busca la key y comprueba que pertenece al usuario autenticado.
     *
     * @throws AuthorizationException
     */
    private function ownedKey(Request $request, string $id): ApiKey
    {
        $apiKey = $this->apiKeyService->find($id);

        if ($apiKey->user_id !== $this->authUser($request)->id) {
            throw new AuthorizationException('No autorizado');
        }

        return $apiKey;
    }
}
