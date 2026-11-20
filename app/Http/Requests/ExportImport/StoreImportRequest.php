<?php

namespace App\Http\Requests\ExportImport;

use App\Http\Requests\BaseFormRequest;

class StoreImportRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.(int) config('export.import.max_kb', 10240)],
        ];
    }
}
