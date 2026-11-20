<?php

namespace App\Http\Controllers\Billing;

use App\Contracts\BillingAccountServiceInterface;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SetPaymentMethodRequest;
use App\Http\Resources\Billing\BillingAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Método de pago por defecto de la empresa activa. Solo se muestra marca y últimos 4.
 */
class TenantPaymentMethodController extends Controller
{
    use UsesActiveTenant;

    public function __construct(private readonly BillingAccountServiceInterface $accounts) {}

    public function show(Request $request): JsonResponse
    {
        $account = $this->accounts->show($this->activeTenant($request), $this->actor($request));

        return $this->sendSuccess($account === null ? null : new BillingAccountResource($account), 'Método de pago');
    }

    public function update(SetPaymentMethodRequest $request): JsonResponse
    {
        $account = $this->accounts->setPaymentMethod($this->activeTenant($request), $this->actor($request), (string) $request->validated('token'));

        return $this->sendSuccess(new BillingAccountResource($account), 'Método de pago actualizado');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
