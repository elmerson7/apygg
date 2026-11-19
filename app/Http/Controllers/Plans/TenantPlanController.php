<?php

namespace App\Http\Controllers\Plans;

use App\Contracts\PlanLimitsInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\SetLimitOverrideRequest;
use App\Http\Requests\Plans\SetTenantPlanRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Plan y límites de un tenant, a nivel PLATAFORMA (`plan-limits.manage` en la ruta).
 */
class TenantPlanController extends Controller
{
    public function __construct(private readonly PlanLimitsInterface $limits) {}

    public function show(int $tenant): JsonResponse
    {
        Tenant::query()->findOrFail($tenant);

        return $this->sendSuccess($this->limits->overview($tenant), 'Plan y límites');
    }

    public function updatePlan(SetTenantPlanRequest $request, int $tenant): JsonResponse
    {
        $this->limits->setPlan($tenant, $request->validated('plan'));

        return $this->sendSuccess($this->limits->overview($tenant), 'Plan actualizado');
    }

    public function setLimit(SetLimitOverrideRequest $request, int $tenant, string $key): JsonResponse
    {
        $value = $request->validated('value');
        $this->limits->setOverride($tenant, $key, $value === null ? null : (int) $value);

        return $this->sendSuccess($this->limits->overview($tenant), 'Límite sobrescrito');
    }

    public function clearLimit(int $tenant, string $key): JsonResponse
    {
        $this->limits->clearOverride($tenant, $key);

        return $this->sendSuccess($this->limits->overview($tenant), 'Override eliminado');
    }
}
