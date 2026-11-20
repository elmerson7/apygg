<?php

namespace App\Http\Resources\Notifications;

use App\Http\Resources\BaseResource;
use App\Integrations\Push\PushMessage;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * Nunca expone el token completo: solo una versión recortada para que el usuario reconozca el dispositivo.
 *
 * @mixin UserDevice
 */
class UserDeviceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserDevice $device */
        $device = $this->resource;

        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'device_id' => $device->device_id,
            'name' => $device->name,
            'token_hint' => PushMessage::mask($device->token),
            'last_seen_at' => $this->formatDate($device->last_seen_at),
            'created_at' => $this->formatDate($device->created_at),
        ];
    }
}
