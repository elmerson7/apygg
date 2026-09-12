<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => [
                'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('menu_items', 'key')->ignore($this->route('menuItem')),
            ],
            'label' => ['sometimes', 'string', 'max:255'],
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
}
