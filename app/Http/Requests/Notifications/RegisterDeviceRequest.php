<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\BaseFormRequest;
use App\Models\UserDevice;
use Illuminate\Validation\Rule;

/**
 * Alta o refresco de un dispositivo push (POST /user/devices).
 */
class RegisterDeviceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in(UserDevice::PLATFORMS)],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
