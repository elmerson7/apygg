<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\StoreWebhookRequest;
use App\Http\Requests\Webhooks\UpdateWebhookRequest;
use App\Http\Resources\Webhooks\WebhookDeliveryResource;
use App\Http\Resources\Webhooks\WebhookResource;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WebhookController
 *
 * Controlador para gestión de webhooks.
 */
class WebhookController extends Controller
{
    protected WebhookService $webhookService;

    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = Webhook::class;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = WebhookResource::class;

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Display a listing of webhooks for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max(1, (int) $request->get('per_page', 15)), 100);

        $query = Webhook::query()
            ->where('user_id', $user->id)
            ->withCount('deliveries')
            ->orderBy('created_at', 'desc');

        // Filtros
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('event')) {
            $query->whereJsonContains('events', $request->get('event'));
        }

        $webhooks = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => WebhookResource::collection($webhooks->items()),
            'meta' => [
                'current_page' => $webhooks->currentPage(),
                'per_page' => $webhooks->perPage(),
                'total' => $webhooks->total(),
                'last_page' => $webhooks->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created webhook.
     *
     * @param  StoreWebhookRequest  $request
     */
    public function store($request): JsonResponse
    {
        /** @var StoreWebhookRequest $request */
        $user = $request->user();
        $validated = $request->validated();

        // Generar secret si no se proporciona
        if (empty($validated['secret'])) {
            $validated['secret'] = bin2hex(random_bytes(32));
        }

        $webhook = Webhook::create(array_merge($validated, [
            'user_id' => $user->id,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Webhook creado exitosamente',
            'data' => new WebhookResource($webhook),
        ], 201);
    }

    /**
     * Display the specified webhook.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::with('user')
            ->withCount('deliveries')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook),
        ]);
    }

    /**
     * Update the specified webhook.
     *
     * @param  UpdateWebhookRequest  $request
     */
    public function update($request, string $id): JsonResponse
    {
        /** @var UpdateWebhookRequest $request */
        $user = $request->user();
        $webhook = Webhook::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validated();
        $webhook->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Webhook actualizado exitosamente',
            'data' => new WebhookResource($webhook->fresh()),
        ]);
    }

    /**
     * Remove the specified webhook.
     */
    public function destroy(string $id): JsonResponse
    {
        $user = request()->user();
        $webhook = Webhook::where('user_id', $user->id)->findOrFail($id);

        $webhook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Webhook eliminado exitosamente',
        ]);
    }

    /**
     * Rotar el secret del webhook.
     */
    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $webhook = Webhook::where('user_id', $user->id)->findOrFail($id);

        $gracePeriodDays = (int) $request->get('grace_period_days', 7);
        $result = $webhook->rotateSecret($gracePeriodDays);

        return response()->json([
            'success' => true,
            'message' => 'Secret rotado exitosamente',
            'data' => [
                'webhook' => new WebhookResource($webhook->fresh()),
                'new_secret' => $result['new_secret'],
                'old_secret_expires_at' => $result['old_secret_expires_at'],
                'grace_period_days' => $result['grace_period_days'],
                'warning' => 'Guarda este secret de forma segura. No se volverá a mostrar.',
            ],
        ]);
    }

    /**
     * Obtener historial de entregas de un webhook.
     */
    public function deliveries(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $webhook = Webhook::where('user_id', $user->id)->findOrFail($id);

        $perPage = min(max(1, (int) $request->get('per_page', 15)), 100);

        $query = WebhookDelivery::where('webhook_id', $webhook->id)
            ->orderBy('created_at', 'desc');

        // Filtros
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('event_type')) {
            $query->where('event_type', $request->get('event_type'));
        }

        $deliveries = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => WebhookDeliveryResource::collection($deliveries->items()),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
                'last_page' => $deliveries->lastPage(),
                'webhook' => [
                    'id' => $webhook->id,
                    'name' => $webhook->name,
                ],
            ],
        ]);
    }

    /**
     * Reenviar manualmente una entrega fallida.
     */
    public function retryDelivery(Request $request, string $id, string $deliveryId): JsonResponse
    {
        $user = $request->user();
        $webhook = Webhook::where('user_id', $user->id)->findOrFail($id);
        $delivery = WebhookDelivery::where('webhook_id', $webhook->id)
            ->findOrFail($deliveryId);

        // Verificar que la entrega esté fallida
        if ($delivery->status !== WebhookDelivery::STATUS_FAILED) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden reenviar entregas fallidas',
                'error' => [
                    'type' => 'invalid_status',
                    'code' => 'DELIVERY_NOT_FAILED',
                ],
            ], 422);
        }

        // Reintentar entrega
        $success = $this->webhookService->retryDelivery($delivery);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo reenviar la entrega. Verifica que el webhook esté activo y que no se haya excedido el máximo de reintentos',
                'error' => [
                    'type' => 'retry_failed',
                    'code' => 'RETRY_FAILED',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Entrega reenviada exitosamente',
            'data' => new WebhookDeliveryResource($delivery->fresh()),
        ]);
    }
}
