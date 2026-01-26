<?php

namespace App\Http\Controllers\ApiKeys;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKeys\RotateApiKeyRequest;
use App\Http\Requests\ApiKeys\StoreApiKeyRequest;
use App\Http\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ApiKeyController
 *
 * Controlador para gestión de API Keys.
 * Extiende Controller base y usa ApiKeyService para lógica de negocio.
 */
class ApiKeyController extends Controller
{
    protected ApiKeyService $apiKeyService;

    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = ApiKey::class;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = ApiKeyResource::class;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Display a listing of API Keys for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->get('per_page', 20);

        $apiKeys = $this->apiKeyService->list($user, $perPage);

        return response()->json([
            'success' => true,
            'data' => ApiKeyResource::collection($apiKeys->items()),
            'meta' => [
                'current_page' => $apiKeys->currentPage(),
                'per_page' => $apiKeys->perPage(),
                'total' => $apiKeys->total(),
                'last_page' => $apiKeys->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created API Key.
     *
     * @param  StoreApiKeyRequest  $request
     */
    public function store($request): JsonResponse
    {
        /** @var StoreApiKeyRequest $request */
        /** @var \App\Models\User $user */
        $user = $request->user();
        $validated = $request->validated();

        $name = $validated['name'];
        $scopes = $validated['scopes'] ?? [];
        $expiresAt = isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null;
        $environment = $validated['environment'] ?? 'live';

        $result = $this->apiKeyService->create($user, $name, $scopes, $expiresAt, $environment);

        // Retornar la key completa solo en creación
        return response()->json([
            'success' => true,
            'message' => 'API Key creada exitosamente',
            'data' => [
                'api_key' => new ApiKeyResource($result['api_key']),
                'key' => $result['key'], // Solo se retorna una vez
                'warning' => 'Guarda esta key de forma segura. No se volverá a mostrar.',
            ],
        ], 201);
    }

    /**
     * Display the specified API Key.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $apiKey = $this->apiKeyService->find($id);

        // Verificar que la key pertenece al usuario autenticado
        if ($apiKey->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
                'error' => [
                    'type' => 'unauthorized',
                    'code' => 'UNAUTHORIZED',
                ],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new ApiKeyResource($apiKey),
        ]);
    }

    /**
     * Remove the specified API Key (revoke).
     */
    public function destroy(string $id): JsonResponse
    {
        $user = request()->user();
        $apiKey = $this->apiKeyService->find($id);

        // Verificar que la key pertenece al usuario autenticado
        if ($apiKey->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
                'error' => [
                    'type' => 'unauthorized',
                    'code' => 'UNAUTHORIZED',
                ],
            ], 403);
        }

        $this->apiKeyService->revoke($id);

        return response()->json([
            'success' => true,
            'message' => 'API Key revocada exitosamente',
        ]);
    }

    /**
     * Rotate an API Key (create new and expire old after grace period).
     */
    public function rotate(RotateApiKeyRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        $apiKey = $this->apiKeyService->find($id);

        // Verificar que la key pertenece al usuario autenticado
        if ($apiKey->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
                'error' => [
                    'type' => 'unauthorized',
                    'code' => 'UNAUTHORIZED',
                ],
            ], 403);
        }

        $validated = $request->validated();
        $name = $validated['name'] ?? null;
        $scopes = $validated['scopes'] ?? null;
        $environment = $validated['environment'] ?? 'live';

        $result = $this->apiKeyService->rotate($id, $name, $scopes, $environment);

        return response()->json([
            'success' => true,
            'message' => 'API Key rotada exitosamente',
            'data' => [
                'api_key' => new ApiKeyResource($result['api_key']),
                'key' => $result['key'], // Solo se retorna una vez
                'old_key_expires_at' => $result['old_key_expires_at'],
                'warning' => 'Guarda esta key de forma segura. No se volverá a mostrar. La key antigua expirará en '.$result['old_key_expires_at'],
            ],
        ], 201);
    }
}
