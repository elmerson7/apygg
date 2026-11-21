<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class OtpRequestRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:phone', 'nullable', 'string', 'email:rfc', 'max:255'],
            // E.164: +34600000000 (canal SMS, si está activo)
            'phone' => ['required_without:email', 'nullable', 'string', 'regex:/^\+[1-9][\d\s().-]{6,20}$/'],
        ];
    }
}
