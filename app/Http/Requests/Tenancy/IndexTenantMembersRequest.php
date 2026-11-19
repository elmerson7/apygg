<?php

namespace App\Http\Requests\Tenancy;

use App\Enums\TenantMembershipStatus;
use App\Http\Requests\BaseFormRequest;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Validation\Rule;

class IndexTenantMembersRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(TenantMembershipStatus::class)],
            'role' => ['sometimes', 'string', Rule::in(TenantRoles::names())],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
