<?php

namespace App\Http\Requests\Permissions;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('permissions', 'name')->ignore($id)],
            'display_name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function getCustomMessages(): array
    {
        return [
            'name.unique' => 'Ya existe un permiso con ese nombre',
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'display_name' => 'nombre visible',
            'description' => 'descripción',
        ];
    }
}
