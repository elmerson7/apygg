<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;
use App\Rules\StrongPassword;
use Illuminate\Validation\Rule;

/**
 * UpdateUserRequest
 *
 * Form Request para validación de actualización de usuarios.
 * Valida email único excepto si es el mismo usuario.
 * Incluye sanitización automática heredada de BaseFormRequest.
 *
 * @package App\Http\Requests\Users
 */
class UpdateUserRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = \App\Models\User::findOrFail($this->route('id'));
        return $this->user()->can('update', $user);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255', 'min:2'],
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', new StrongPassword()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.string' => 'El nombre debe ser texto',
            'name.min' => 'El nombre debe tener al menos 2 caracteres',
            'name.max' => 'El nombre no puede exceder 255 caracteres',
            'email.email' => 'El email debe tener un formato válido',
            'email.unique' => 'Este email ya está registrado',
            'email.max' => 'El email no puede exceder 255 caracteres',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
        ];
    }
}
