<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class IndexTenantInvitationsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['pending', 'accepted', 'revoked', 'expired'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
