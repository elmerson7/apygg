<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Rules\AllowedResetUrl;
use Illuminate\Contracts\Validation\ValidationRule;

class ForgotPasswordRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'reset_url' => [
                'nullable',
                'url',
                'max:500',
                new AllowedResetUrl,
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    protected function getCustomMessages(): array
    {
        return [
            'email.required' => 'El email es requerido.',
            'email.email' => 'El email debe ser una dirección válida.',
            'reset_url.url' => 'La URL de reset debe ser una URL válida.',
            'reset_url.max' => 'La URL de reset no puede exceder 500 caracteres.',
        ];
    }
}
