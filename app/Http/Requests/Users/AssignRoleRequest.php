<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * AssignRoleRequest
 *
 * Form Request para validación de asignación de roles a usuarios.
 * Incluye sanitización automática heredada de BaseFormRequest.
 *
 * @package App\Http\Requests\Users
 */
class AssignRoleRequest extends BaseFormRequest
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
        return [
            'role_ids' => ['required', 'array', 'min:1', 'max:10'], // Máximo 10 roles por usuario
            'role_ids.*' => ['required', 'string', 'uuid', 'exists:roles,id', 'distinct'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'role_ids.required' => 'Los roles son requeridos',
            'role_ids.array' => 'Los roles deben ser un array',
            'role_ids.min' => 'Debe asignar al menos un rol',
            'role_ids.max' => 'No se pueden asignar más de 10 roles',
            'role_ids.*.required' => 'Cada rol es requerido',
            'role_ids.*.uuid' => 'Cada rol debe ser un UUID válido',
            'role_ids.*.exists' => 'Uno o más roles no existen',
            'role_ids.*.distinct' => 'No se pueden asignar roles duplicados',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'role_ids' => 'roles',
            'role_ids.*' => 'rol',
        ];
    }
}
