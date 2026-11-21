<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class SocialLoginRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // OIDC (Google, Apple...): id_token. OAuth2 sin id_token (GitHub...): access_token. Con uno basta.
            'id_token' => ['required_without:access_token', 'nullable', 'string', 'max:8192'],
            'access_token' => ['required_without:id_token', 'nullable', 'string', 'max:2048'],
            // El mismo valor que la app envió al proveedor al iniciar el flujo (va dentro del id_token).
            'nonce' => ['nullable', 'string', 'max:255'],
        ];
    }
}
