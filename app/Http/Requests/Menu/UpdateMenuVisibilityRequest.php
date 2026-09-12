<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\BaseFormRequest;

class UpdateMenuVisibilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', 'exists:menu_items,key'],
            'items.*.role_ids' => ['sometimes', 'array'],
            'items.*.role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Indica al menos un ítem',
            'items.*.key.exists' => 'Alguno de los ítems indicados no existe',
        ];
    }
}
