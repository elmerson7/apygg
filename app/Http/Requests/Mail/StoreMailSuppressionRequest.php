<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class StoreMailSuppressionRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
