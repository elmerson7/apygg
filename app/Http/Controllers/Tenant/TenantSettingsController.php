<?php

namespace App\Http\Controllers\Tenant;

use App\Contracts\SettingsRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SetSettingValueRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ajustes del tenant activo (middleware `tenant` + `tenant-settings.*` en la ruta).
 */
class TenantSettingsController extends Controller
{
    public function __construct(private readonly SettingsRegistryInterface $registry) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess($this->registry->tenantView(), 'Ajustes del tenant');
    }

    public function update(SetSettingValueRequest $request, string $key): JsonResponse
    {
        $value = $this->registry->setTenant($request->user(), $key, $request->input('value'));

        return $this->sendSuccess(['key' => $key, 'value' => $value], 'Ajuste actualizado');
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->registry->resetTenant($request->user(), $key);

        return $this->sendSuccess(null, 'Ajuste restablecido');
    }
}
