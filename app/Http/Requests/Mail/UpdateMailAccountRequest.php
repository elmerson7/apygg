<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class UpdateMailAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'mail_provider_id' => ['nullable', 'integer', 'exists:mail_providers,id'],
            'from_email' => ['sometimes', 'email', 'max:255'],
            'from_name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'priority' => ['integer', 'min:0'],
        ];
    }
}
