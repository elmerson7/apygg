<?php

namespace App\Http\Requests\Billing;

use App\Enums\InvoiceStatus;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class IndexInvoicesRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(InvoiceStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
