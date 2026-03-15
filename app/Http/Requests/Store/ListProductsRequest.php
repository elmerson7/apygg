<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\BaseFormRequest;

class ListProductsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'in:gifts,superlikes,boosts,premium'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
