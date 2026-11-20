<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class TwoFactorReauthRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
