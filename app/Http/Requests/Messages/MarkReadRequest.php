<?php

namespace App\Http\Requests\Messages;

use App\Http\Requests\BaseFormRequest;

class MarkReadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'message_ids'   => ['required', 'array', 'min:1'],
            'message_ids.*' => ['uuid'],
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'message_ids' => 'mensajes',
        ];
    }
}
