<?php

namespace App\Modules\Auth\Requests;

use App\Core\Requests\BaseRequest;

class LoginRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRule(required: true),
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe tener un formato válido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'email' => 'email',
            'password' => 'contraseña',
        ];
    }
}
