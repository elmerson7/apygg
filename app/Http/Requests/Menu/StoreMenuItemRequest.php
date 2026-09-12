<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\BaseFormRequest;

class StoreMenuItemRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/', 'unique:menu_items,key'],
            'label' => ['required', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'permission' => ['nullable', 'string', 'max:150', 'exists:permissions,name'],
            'parent_key' => ['nullable', 'string', 'max:100', 'exists:menu_items,key'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'La clave del ítem es obligatoria',
            'key.unique' => 'Ya existe un ítem con esa clave',
            'key.regex' => 'La clave solo admite minúsculas, números, puntos, guiones y guiones bajos',
            'permission.exists' => 'El permiso indicado no existe',
            'parent_key.exists' => 'El ítem padre indicado no existe',
        ];
    }
}
