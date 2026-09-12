<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class UpdateMailProviderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'driver' => ['sometimes', 'string', 'in:resend,smtp,ses,postmark,sendmail,log,array'],
            'config' => ['nullable', 'array'],
            'config.host' => ['nullable', 'string', 'max:255'],
            'config.port' => ['nullable', 'integer', 'between:1,65535'],
            'config.encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'config.username' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
