<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\WebhookServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\StoreWebhookRequest;
use App\Http\Requests\Webhooks\UpdateWebhookRequest;
use App\Http\Resources\Webhooks\WebhookDeliveryResource;
use App\Http\Resources\Webhooks\WebhookResource;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    protected ?string $resource = WebhookResource::class;

    public function __construct(protected WebhookServiceInterface $webhookService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Webhook::query()
            ->where('user_id', $this->authUser($request)->id)
            ->withCount('deliveries')
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('event')) {
            $query->whereJsonContains('events', $request->input('event'));
        }

        return $this->sendPaginated($query->paginate($this->perPage($request, 20)));
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (empty($validated['secret'])) {
            $validated['secret'] = bin2hex(random_bytes(32));
        }

        $webhook = Webhook::create(array_merge($validated, ['user_id' => $this->authUser($request)->id]));

        // El secreto se muestra SOLO ahora (y al rotarlo): después no se puede volver a consultar
        return $this->sendSuccess([
            ...(new WebhookResource($webhook))->resolve($request),
            'secret' => $webhook->secret,
            'warning' => 'Guarda este secret de forma segura. No se volverá a mostrar.',
        ], 'Webhook creado exitosamente', 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::with('user')
            ->withCount('deliveries')
            ->where('user_id', $this->authUser($request)->id)
            ->findOrFail($id);

        return $this->sendSuccess($webhook, 'Webhook obtenido exitosamente');
    }

    public function update(UpdateWebhookRequest $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $this->authUser($request)->id)->findOrFail($id);
        $webhook->update($request->validated());

        return $this->sendSuccess($webhook->fresh(), 'Webhook actualizado exitosamente');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $this->authUser($request)->id)->findOrFail($id);
        $webhook->delete();

        return $this->sendSuccess(null, 'Webhook eliminado exitosamente');
    }

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $this->authUser($request)->id)->findOrFail($id);
        $gracePeriodDays = (int) $request->input('grace_period_days', 7);
        $result = $webhook->rotateSecret($gracePeriodDays);

        return $this->sendSuccess([
            'webhook' => new WebhookResource($webhook->fresh()),
            'new_secret' => $result['new_secret'],
            'old_secret_expires_at' => $result['old_secret_expires_at'],
            'grace_period_days' => $result['grace_period_days'],
            'warning' => 'Guarda este secret de forma segura. No se volverá a mostrar.',
        ], 'Secret rotado exitosamente');
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $this->authUser($request)->id)->findOrFail($id);

        $query = WebhookDelivery::where('webhook_id', $webhook->id)->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        $deliveries = $query->paginate($this->perPage($request, 20));

        return $this->sendPaginated($deliveries, [
            'webhook' => ['id' => $webhook->id, 'name' => $webhook->name],
        ], WebhookDeliveryResource::class);
    }

    public function retryDelivery(Request $request, string $id, string $deliveryId): JsonResponse
    {
        $webhook = Webhook::where('user_id', $this->authUser($request)->id)->findOrFail($id);
        $delivery = WebhookDelivery::where('webhook_id', $webhook->id)->findOrFail($deliveryId);

        if ($delivery->status !== WebhookDelivery::STATUS_FAILED) {
            return $this->sendError('Solo se pueden reenviar entregas fallidas', 422, [
                'type' => 'invalid_status',
                'code' => 'DELIVERY_NOT_FAILED',
            ]);
        }

        if (! $this->webhookService->retryDelivery($delivery)) {
            return $this->sendError(
                'No se pudo reenviar la entrega. Verifica que el webhook esté activo y que no se haya excedido el máximo de reintentos',
                422,
                ['type' => 'retry_failed', 'code' => 'RETRY_FAILED'],
            );
        }

        return $this->sendSuccess(new WebhookDeliveryResource($delivery->fresh()), 'Entrega reenviada exitosamente');
    }
}
