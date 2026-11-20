<?php

namespace App\Http\Controllers\Billing;

use App\Contracts\SubscriptionServiceInterface;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelSubscriptionRequest;
use App\Http\Requests\Billing\ChangePlanRequest;
use App\Http\Requests\Billing\SubscribeRequest;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suscripción de la empresa activa (X-Tenant). Cada operación vuelve a comprobar el rol del actor EN ese tenant.
 */
class TenantSubscriptionController extends Controller
{
    use UsesActiveTenant;

    public function __construct(private readonly SubscriptionServiceInterface $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->current($this->activeTenant($request), $this->actor($request));

        return $this->sendSuccess($subscription === null ? null : new SubscriptionResource($subscription), 'Suscripción');
    }

    public function store(SubscribeRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->subscribe(
            $this->activeTenant($request),
            $this->actor($request),
            (string) $request->validated('plan'),
            $request->validated('currency'),
            $request->billingInterval(),
            $request->validated('payment_token'),
        );

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Suscripción creada', 201);
    }

    public function preview(ChangePlanRequest $request): JsonResponse
    {
        $preview = $this->subscriptions->previewChange(
            $this->activeTenant($request),
            $this->actor($request),
            (string) $request->validated('plan'),
            $request->validated('currency'),
            $request->billingInterval(),
        );

        return $this->sendSuccess($preview, 'Vista previa del cambio de plan');
    }

    public function change(ChangePlanRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->changePlan(
            $this->activeTenant($request),
            $this->actor($request),
            (string) $request->validated('plan'),
            $request->validated('currency'),
            $request->billingInterval(),
        );

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Plan actualizado');
    }

    public function cancel(CancelSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->cancel($this->activeTenant($request), $this->actor($request), $request->immediately());

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Baja registrada');
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->resume($this->activeTenant($request), $this->actor($request));

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Suscripción reanudada');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
