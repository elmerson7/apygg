<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class LoginRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    /**
     * Si el identificador es un email se compara en minúsculas (el username se respeta tal cual).
     */
    protected function prepareForValidation(): void
    {
        $login = $this->input('login');

        if (is_string($login) && str_contains($login, '@')) {
            $this->merge(['login' => mb_strtolower(trim($login))]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'min:1'],
            'password' => ['required'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'login.required' => 'El email o username es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'login' => 'email o username',
            'password' => 'contraseña',
        ];
    }
}
