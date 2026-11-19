<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Validation\Rule;

class StoreTenantInvitationRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['sometimes', 'string', Rule::in(TenantRoles::names())],
        ];
    }

    public function role(): string
    {
        return (string) ($this->validated('role') ?? TenantRoles::default());
    }
}
