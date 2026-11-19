<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Rules\NotBlacklisted;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    /**
     * El email se guarda y compara en minúsculas: `Admin@x.com` y `admin@x.com` son la misma cuenta.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [...$this->emailRule(required: true, unique: true, table: 'users'), new NotBlacklisted('email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe tener un formato válido.',
            'email.unique' => 'Este email ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, mixed>
     */
    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'email',
            'password' => 'contraseña',
            'password_confirmation' => 'confirmación de contraseña',
        ];
    }
}
