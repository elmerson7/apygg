<?php

namespace App\Http\Requests\Mail;

use App\Http\Requests\BaseFormRequest;

class UpdateMailTemplateRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_name' => ['sometimes', 'string', 'max:255'],
            'mail_account_id' => ['nullable', 'integer', 'exists:mail_accounts,id'],
            'is_active' => ['boolean'],
            'subject_template' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'mail_account_id.exists' => 'La cuenta de correo indicada no existe',
        ];
    }
}
