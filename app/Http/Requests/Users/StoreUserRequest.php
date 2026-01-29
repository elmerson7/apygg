<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;
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
        return $this->user()->can('create', \App\Models\User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', new StrongPassword],
            'identity_document' => ['sometimes', 'nullable', 'string', 'max:50'],
            'role_ids' => ['sometimes', 'nullable', 'array'],
            'role_ids.*' => ['required', 'string', 'uuid', 'exists:roles,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.required' => 'El nombre es requerido',
            'name.string' => 'El nombre debe ser texto',
            'name.min' => 'El nombre debe tener al menos 2 caracteres',
            'name.max' => 'El nombre no puede exceder 255 caracteres',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email debe tener un formato válido',
            'email.unique' => 'Este email ya está registrado',
            'email.max' => 'El email no puede exceder 255 caracteres',
            'password.required' => 'La contraseña es requerida',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'identity_document.string' => 'El documento de identidad debe ser texto',
            'identity_document.max' => 'El documento de identidad no puede exceder 50 caracteres',
            'role_ids.array' => 'Los roles deben ser un array',
            'role_ids.*.required' => 'Cada rol es requerido',
            'role_ids.*.uuid' => 'Cada rol debe ser un UUID válido',
            'role_ids.*.exists' => 'Uno o más roles no existen',
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
            'identity_document' => 'documento de identidad',
            'role_ids' => 'roles',
            'role_ids.*' => 'rol',
        ];
    }
}
