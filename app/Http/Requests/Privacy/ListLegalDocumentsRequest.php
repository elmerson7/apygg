<?php

namespace App\Http\Requests\Privacy;

use App\Http\Requests\BaseFormRequest;

class ListLegalDocumentsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
