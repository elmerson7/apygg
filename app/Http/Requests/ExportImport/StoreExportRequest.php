<?php

namespace App\Http\Requests\ExportImport;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreExportRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in((array) config('export.formats'))],
            'filters' => ['sometimes', 'array'],
        ];
    }
}
