<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class LogoutRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'refresh_token' => [
                'sometimes',
                'string',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'refresh_token.string' => 'El token de actualización debe ser una cadena válida.',
        ]);
    }

    /**
     * Get the refresh token from the request body.
     */
    public function getRefreshToken(): ?string
    {
        return $this->input('refresh_token');
    }
}
