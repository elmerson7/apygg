<?php

namespace App\Http\Controllers\Tenant;

use App\Contracts\FeatureFlagServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Flags evaluadas para el tenant activo y el usuario autenticado (solo lectura).
 */
class TenantFeaturesController extends Controller
{
    public function __construct(private readonly FeatureFlagServiceInterface $features) {}

    public function index(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->features->all($request->user()), 'Features del tenant');
    }
}
