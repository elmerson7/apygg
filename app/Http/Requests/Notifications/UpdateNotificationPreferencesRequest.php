<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\BaseFormRequest;

/**
 * Preferencias propias: opt-out por tipo y canal, y horas de silencio (HH:MM, zona horaria del usuario).
 * Que el tipo y el canal existan y que no sea un tipo obligatorio lo valida el servicio (422 de dominio).
 */
class UpdateNotificationPreferencesRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['sometimes', 'array', 'max:100'],
            'preferences.*.type' => ['required', 'string', 'max:64'],
            'preferences.*.channel' => ['required', 'string', 'max:16'],
            'preferences.*.enabled' => ['required', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
