<?php

namespace App\Http\Requests\Permissions;

use App\Http\Requests\BaseFormRequest;

class StorePermissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:permissions,name'],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function getCustomMessages(): array
    {
        return [
            'name.required' => 'El nombre del permiso es obligatorio',
            'name.unique' => 'Ya existe un permiso con ese nombre',
            'display_name.required' => 'El nombre visible es obligatorio',
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
