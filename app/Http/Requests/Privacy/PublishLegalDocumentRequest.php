<?php

namespace App\Http\Requests\Privacy;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class PublishLegalDocumentRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'locale' => [
                'required', 'string', 'max:10', 'regex:/^[a-z]{2}([_-][A-Za-z]{2})?$/',
                // Una traducción de una versión no se publica dos veces (los documentos son inmutables).
                Rule::unique('legal_documents', 'locale')->where(fn ($q) => $q->where('key', $this->input('key'))->where('version', $this->input('version'))),
            ],
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:500000'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
