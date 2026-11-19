<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Validation\Rule;

class UpdateTenantMemberRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(TenantRoles::names())],
        ];
    }
}
