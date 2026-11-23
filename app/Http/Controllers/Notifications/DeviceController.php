<?php

namespace App\Http\Controllers\Notifications;

use App\Contracts\DeviceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\RegisterDeviceRequest;
use App\Http\Resources\Notifications\UserDeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispositivos push del usuario en sesión. Son de la persona, no de un tenant: sin middleware `tenant`.
 */
class DeviceController extends Controller
{
    protected ?string $resource = UserDeviceResource::class;

    public function __construct(private readonly DeviceServiceInterface $devices) {}

    public function index(Request $request): JsonResponse
    {
        return $this->sendSuccess(UserDeviceResource::collection($this->devices->list($request->user())), 'Dispositivos');
    }

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        /** @var array{token: string, platform: string, device_id?: string|null, name?: string|null} $data */
        $data = $request->validated();

        return $this->sendSuccess($this->devices->register($request->user(), $data), 'Dispositivo registrado', 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->devices->remove($request->user(), $id);

        return $this->sendSuccess(null, 'Dispositivo eliminado');
    }
}
