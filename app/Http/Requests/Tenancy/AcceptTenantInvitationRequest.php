<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;

class AcceptTenantInvitationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
        ];
    }
}
