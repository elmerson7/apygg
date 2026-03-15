<?php

namespace App\Http\Requests\Messages;

use App\Http\Requests\BaseFormRequest;

class SendMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'uuid'],
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:2000'],
            'attachments' => ['required_without:body', 'nullable', 'array', 'max:5'],
            'attachments.*' => ['uuid'],
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'conversation_id' => 'conversación',
            'body' => 'mensaje',
            'attachments' => 'archivos adjuntos',
        ];
    }
}
