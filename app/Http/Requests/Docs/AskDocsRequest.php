<?php

namespace App\Http\Requests\Docs;

use App\Http\Requests\BaseFormRequest;

class AskDocsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'scope' => ['nullable', 'string', 'max:30'],
            'conversation_id' => ['nullable', 'integer', 'exists:doc_conversations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'Escribe una pregunta',
            'question.max' => 'La pregunta es demasiado larga',
        ];
    }
}
