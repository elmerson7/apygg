<?php

namespace App\Http\Controllers\Billing;

use App\Contracts\PlanCatalogInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo público de planes (sin autenticación): los planes privados no salen.
 */
class PlanController extends Controller
{
    public function __construct(private readonly PlanCatalogInterface $catalog) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess(PlanResource::collection($this->catalog->publicPlans()), 'Planes disponibles');
    }
}
