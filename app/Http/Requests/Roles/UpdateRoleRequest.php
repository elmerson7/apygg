<?php

namespace App\Http\Requests\Roles;

use App\Http\Requests\BaseFormRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Role::findOrFail($this->route('id')));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($id)],
            'display_name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.unique' => 'Ya existe un rol con ese nombre',
            'permission_ids.*.exists' => 'Uno o más permisos no existen',
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
