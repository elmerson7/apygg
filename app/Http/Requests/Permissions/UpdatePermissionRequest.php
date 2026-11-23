<?php

namespace App\Http\Requests\Permissions;

use App\Http\Requests\BaseFormRequest;
use App\Models\Permission;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Permission::findOrFail($this->route('id')));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('permissions', 'name')->ignore($id)],
            'display_name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.unique' => 'Ya existe un permiso con ese nombre',
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
        ];
    }
}
