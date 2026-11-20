<?php

namespace App\Http\Requests\Privacy;

use App\Http\Requests\BaseFormRequest;

class AcceptConsentsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:20'],
            'documents.*.key' => ['required', 'string', 'max:64'],
            'documents.*.version' => ['required', 'string', 'max:32'],
        ];
    }
}
