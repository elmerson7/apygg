<?php

namespace App\Http\Requests\Roles;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends BaseFormRequest
{
    protected ?string $roleId = null;

    public function setRoleId(string $id): static
    {
        $this->roleId = $id;

        return $this;
    }

    public function rules(): array
    {
        $id = $this->roleId ?? $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($id)],
            'display_name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    protected function getCustomMessages(): array
    {
        return [
            'name.unique' => 'Ya existe un rol con ese nombre',
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
