<?php

namespace App\Http\Requests\Roles;

use App\Http\Requests\BaseFormRequest;
use App\Models\Role;

/**
 * SyncRolePermissionsRequest
 *
 * PUT /roles/{id}/permissions: reemplaza los permisos de un rol.
 */
class SyncRolePermissionsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignPermission', Role::findOrFail($this->route('id')));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
