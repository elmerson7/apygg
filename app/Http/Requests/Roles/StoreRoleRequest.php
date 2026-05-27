<?php

namespace App\Http\Requests\Roles;

use App\Http\Requests\BaseFormRequest;

class StoreRoleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    protected function getCustomMessages(): array
    {
        return [
            'name.required' => 'El nombre del rol es obligatorio',
            'name.unique' => 'Ya existe un rol con ese nombre',
            'display_name.required' => 'El nombre visible es obligatorio',
            'permission_ids.*.exists' => 'Uno o más permisos no existen',
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'display_name' => 'nombre visible',
            'description' => 'descripción',
            'permission_ids' => 'permisos',
        ];
    }
}
