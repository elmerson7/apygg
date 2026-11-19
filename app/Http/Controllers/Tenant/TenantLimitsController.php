<?php

namespace App\Http\Controllers\Tenant;

use App\Contracts\PlanLimitsInterface;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Plan y límites del tenant activo (solo lectura).
 */
class TenantLimitsController extends Controller
{
    public function __construct(
        private readonly PlanLimitsInterface $limits,
        private readonly TenantContext $tenants,
    ) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess($this->limits->overview($this->tenants->require()->id), 'Plan y límites del tenant');
    }
}
