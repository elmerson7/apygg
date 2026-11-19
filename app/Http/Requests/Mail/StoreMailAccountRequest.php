<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class StoreMailAccountRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mail_provider_id' => ['nullable', 'integer', 'exists:mail_providers,id'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'priority' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_email.required' => 'La dirección de envío es obligatoria',
            'from_email.email' => 'La dirección de envío no es válida',
            'from_name.required' => 'El nombre del remitente es obligatorio',
            'mail_provider_id.exists' => 'El proveedor indicado no existe',
        ];
    }
}
