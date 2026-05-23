<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;
use App\Models\User;
use App\Rules\StrongPassword;

/**
 * StoreUserRequest
 *
 * Form Request para validación de creación de usuarios.
 * Incluye sanitización automática heredada de BaseFormRequest.
 */
class StoreUserRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100', 'min:2'],
            'last_name' => ['required', 'string', 'max:100', 'min:2'],
            'username' => ['required', 'string', 'max:50', 'min:3', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', new StrongPassword],
            'identity_document' => [
                'required',
                'string',
                'regex:/^[0-9]{9,50}$/',
                'unique:users,identity_document',
            ],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'string', 'exists:roles,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'first_name.required' => 'El nombre es requerido',
            'first_name.string' => 'El nombre debe ser texto',
            'first_name.min' => 'El nombre debe tener al menos 2 caracteres',
            'first_name.max' => 'El nombre no puede exceder 100 caracteres',
            'last_name.required' => 'El apellido es requerido',
            'last_name.string' => 'El apellido debe ser texto',
            'last_name.min' => 'El apellido debe tener al menos 2 caracteres',
            'last_name.max' => 'El apellido no puede exceder 100 caracteres',
            'username.required' => 'El username es requerido',
            'username.string' => 'El username debe ser texto',
            'username.min' => 'El username debe tener al menos 3 caracteres',
            'username.max' => 'El username no puede exceder 50 caracteres',
            'username.unique' => 'Este username ya está en uso',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email debe tener un formato válido',
            'email.unique' => 'Este email ya está registrado',
            'email.max' => 'El email no puede exceder 255 caracteres',
            'password.required' => 'La contraseña es requerida',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'identity_document.required' => 'El documento de identidad es requerido',
            'identity_document.string' => 'El documento de identidad debe ser texto',
            'identity_document.regex' => 'El documento de identidad debe tener al menos 9 dígitos y contener solo números',
            'identity_document.unique' => 'Este documento de identidad ya está registrado',
            'role_ids.required' => 'Debe asignar al menos un rol',
            'role_ids.array' => 'Los roles deben ser un array',
            'role_ids.min' => 'Debe asignar al menos un rol',
            'role_ids.*.required' => 'Cada rol es requerido',
            'role_ids.*.exists' => 'Uno o más roles no existen',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellido',
            'username' => 'username',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'identity_document' => 'documento de identidad',
            'role_ids' => 'roles',
            'role_ids.*' => 'rol',
        ];
    }
}
