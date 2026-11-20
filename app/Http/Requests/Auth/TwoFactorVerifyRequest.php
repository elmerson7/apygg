<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class TwoFactorVerifyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
