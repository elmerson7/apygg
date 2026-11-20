<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

class StartImpersonationRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Obligatorio por `audit.reason_required` (impersonation.start); el servicio lo exige con 422 audit_reason_required.
            'reason' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
