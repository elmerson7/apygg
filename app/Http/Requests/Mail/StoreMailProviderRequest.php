<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class StoreMailProviderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'string', 'in:resend,smtp,ses,postmark,sendmail,log,array'],
            'config' => ['nullable', 'array'],
            'config.host' => ['nullable', 'string', 'max:255'],
            'config.port' => ['nullable', 'integer', 'between:1,65535'],
            'config.encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'config.username' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del proveedor es obligatorio',
            'driver.required' => 'El driver es obligatorio',
            'driver.in' => 'El driver indicado no está soportado',
        ];
    }
}
