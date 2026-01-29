<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreSettingsRequest;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\Settings\SettingsResource;
use App\Models\Settings;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SettingsController
 *
 * Controlador para gestión de Settings.
 * Extiende Controller base y usa SettingsService para lógica de negocio.
 */
class SettingsController extends Controller
{
    protected SettingsService $settingsService;

    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = Settings::class;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = SettingsResource::class;

    /**
     * Relaciones permitidas para eager loading
     */
    protected array $allowedRelations = [];

    /**
     * Campos permitidos para ordenamiento
     */
    protected array $allowedSortFields = ['key', 'group', 'created_at', 'updated_at'];

    /**
     * Campos permitidos para filtrado
     */
    protected array $allowedFilterFields = ['group', 'is_public'];

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Display a listing of settings.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Settings::class);

        $filters = [
            'group' => $request->get('group'),
            'is_public' => $request->get('is_public'),
            'search' => $request->get('search'),
            'sort' => $request->get('sort', 'created_at'),
            'direction' => $request->get('direction', 'desc'),
            'per_page' => $request->get('per_page', 15),
        ];

        $settings = $this->settingsService->list($filters);

        return $this->sendPaginated($settings);
    }

    /**
     * Display the specified setting.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $setting = $this->settingsService->find($id);

        $this->authorize('view', $setting);

        return $this->sendSuccess($setting, 'Setting obtenido exitosamente');
    }

    /**
     * Store a newly created setting.
     *
     * @param  StoreSettingsRequest  $request
     */
    public function store($request): JsonResponse
    {
        /** @var StoreSettingsRequest $request */
        $this->authorize('create', Settings::class);

        $validated = $request->validated();
        $setting = $this->settingsService->create($validated);

        return $this->sendSuccess($setting, 'Setting creado exitosamente', 201);
    }

    /**
     * Update the specified setting.
     *
     * @param  UpdateSettingsRequest  $request
     */
    public function update($request, string $id): JsonResponse
    {
        /** @var UpdateSettingsRequest $request */
        $setting = $this->settingsService->find($id);

        $this->authorize('update', $setting);

        $validated = $request->validated();
        $setting = $this->settingsService->update($id, $validated);

        return $this->sendSuccess($setting, 'Setting actualizado exitosamente');
    }

    /**
     * Remove the specified setting.
     */
    public function destroy(string $id): JsonResponse
    {
        $setting = $this->settingsService->find($id);

        $this->authorize('delete', $setting);

        $this->settingsService->delete($id);

        return $this->sendSuccess(null, 'Setting eliminado exitosamente');
    }

    /**
     * Obtener setting por key
     */
    public function getByKey(Request $request, string $key): JsonResponse
    {
        $this->authorize('viewAny', Settings::class);

        $setting = $this->settingsService->findByKey($key);

        if (! $setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting no encontrado',
            ], 404);
        }

        $this->authorize('view', $setting);

        return $this->sendSuccess($setting, 'Setting obtenido exitosamente');
    }
}
