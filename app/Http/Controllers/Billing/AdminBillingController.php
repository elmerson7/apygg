<?php

namespace App\Http\Controllers\Billing;

use App\Contracts\InvoiceServiceInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AssignPlanRequest;
use App\Http\Requests\Billing\GrantFreePeriodRequest;
use App\Http\Requests\Billing\MarkInvoicePaidRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operaciones de plataforma sobre la facturación de cualquier empresa (permiso `billing.manage`).
 * Ambas exigen motivo y quedan auditadas.
 */
class AdminBillingController extends Controller
{
    public function __construct(
        private readonly InvoiceServiceInterface $invoices,
        private readonly SubscriptionServiceInterface $subscriptions,
    ) {}

    public function markPaid(MarkInvoicePaidRequest $request, int $id): JsonResponse
    {
        $invoice = $this->invoices->markPaidManually($id, $this->actor($request), (string) $request->validated('reference'), (string) $request->validated('reason'));

        return $this->sendSuccess(new InvoiceResource($invoice), 'Factura marcada como pagada');
    }

    public function grantFreePeriod(GrantFreePeriodRequest $request, int $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->grantFreePeriod($tenant, $this->actor($request), (int) $request->validated('days'), (string) $request->validated('reason'));

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Periodo gratis concedido');
    }

    /**
     * Asigna un plan (público o privado) a la empresa: la contrata o le cambia el plan. Motivo obligatorio y auditado.
     */
    public function assignPlan(AssignPlanRequest $request, int $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->assignPlan(
            $tenant,
            $this->actor($request),
            (string) $request->validated('plan'),
            $request->validated('currency'),
            $request->billingInterval(),
            (string) $request->validated('reason'),
        );

        return $this->sendSuccess(new SubscriptionResource($subscription), 'Plan asignado');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
