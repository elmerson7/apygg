<?php

namespace App\Http\Controllers\Settings;

use App\Contracts\SettingsRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SetSettingValueRequest;
use Illuminate\Http\JsonResponse;

/**
 * Ajustes declarados, nivel PLATAFORMA (permiso `system.settings` en la ruta).
 */
class SettingsRegistryController extends Controller
{
    public function __construct(private readonly SettingsRegistryInterface $registry) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess($this->registry->platformView(), 'Ajustes declarados');
    }

    public function update(SetSettingValueRequest $request, string $key): JsonResponse
    {
        $value = $this->registry->setPlatform($key, $request->input('value'));

        return $this->sendSuccess(['key' => $key, 'value' => $value], 'Ajuste actualizado');
    }

    public function destroy(string $key): JsonResponse
    {
        $this->registry->resetPlatform($key);

        return $this->sendSuccess(null, 'Ajuste restablecido');
    }
}
