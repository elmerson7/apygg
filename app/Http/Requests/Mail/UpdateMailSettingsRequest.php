<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;
use App\Models\Mail\MailSetting;

class UpdateMailSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            MailSetting::KEY_GLOBAL_PAUSE => ['sometimes', 'boolean'],
            MailSetting::KEY_DAILY_LIMIT => ['sometimes', 'integer', 'min:0'],
            MailSetting::KEY_RATE_LIMIT => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'daily_limit.min' => 'El límite diario no puede ser negativo (0 = sin límite)',
            'rate_limit.min' => 'El límite por minuto no puede ser negativo (0 = sin límite)',
        ];
    }
}
