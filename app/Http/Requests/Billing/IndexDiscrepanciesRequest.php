<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;
use App\Models\PaymentDiscrepancy;
use Illuminate\Validation\Rule;

class IndexDiscrepanciesRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([PaymentDiscrepancy::STATUS_OPEN, PaymentDiscrepancy::STATUS_RESOLVED])],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
