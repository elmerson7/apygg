<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\MaintenanceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnableMaintenanceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Modo mantenimiento de la API (docs/maintenance-mode.md), solo `maintenance.manage`.
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceServiceInterface $maintenance) {}

    public function show(): JsonResponse
    {
        $state = $this->maintenance->state();

        return $this->sendSuccess(['enabled' => $state !== null, 'state' => $state]);
    }

    public function enable(EnableMaintenanceRequest $request): JsonResponse
    {
        $user = $request->user('api');

        $this->maintenance->enable(
            $request->input('message'),
            $request->filled('until') ? Carbon::parse((string) $request->input('until')) : null,
            $user instanceof User ? $user : null,
        );

        return $this->sendSuccess(['enabled' => true, 'state' => $this->maintenance->state()], 'Modo mantenimiento activado');
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $this->maintenance->disable($user instanceof User ? $user : null);

        $state = $this->maintenance->state();

        return $this->sendSuccess(['enabled' => $state !== null, 'state' => $state], 'Modo mantenimiento desactivado');
    }
}
