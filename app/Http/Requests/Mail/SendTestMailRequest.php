<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class SendTestMailRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Indica a qué dirección enviar la prueba',
            'email.email' => 'La dirección indicada no es válida',
        ];
    }
}
