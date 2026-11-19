<?php

namespace App\Http\Controllers\Features;

use App\Contracts\FeatureFlagServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Features\SetFeatureOverrideRequest;
use App\Http\Requests\Features\UpdateFeatureFlagRequest;
use Illuminate\Http\JsonResponse;

/**
 * Feature flags a nivel PLATAFORMA (`feature-flags.read|manage` en la ruta).
 */
class FeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlagServiceInterface $features) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess($this->features->platformView(), 'Feature flags');
    }

    public function update(UpdateFeatureFlagRequest $request, string $key): JsonResponse
    {
        return $this->sendSuccess($this->features->setFlag($key, $request->validated()), 'Feature flag actualizada');
    }

    public function destroy(string $key): JsonResponse
    {
        $this->features->resetFlag($key);

        return $this->sendSuccess(null, 'Feature flag restablecida');
    }

    public function setTenantOverride(SetFeatureOverrideRequest $request, string $key, int $tenantId): JsonResponse
    {
        $this->features->setTenantOverride($key, $tenantId, (bool) $request->validated('enabled'));

        return $this->sendSuccess(null, 'Override de tenant guardado');
    }

    public function clearTenantOverride(string $key, int $tenantId): JsonResponse
    {
        $this->features->clearTenantOverride($key, $tenantId);

        return $this->sendSuccess(null, 'Override de tenant eliminado');
    }

    public function setUserOverride(SetFeatureOverrideRequest $request, string $key, int $userId): JsonResponse
    {
        $this->features->setUserOverride($key, $userId, (bool) $request->validated('enabled'));

        return $this->sendSuccess(null, 'Override de usuario guardado');
    }

    public function clearUserOverride(string $key, int $userId): JsonResponse
    {
        $this->features->clearUserOverride($key, $userId);

        return $this->sendSuccess(null, 'Override de usuario eliminado');
    }
}
