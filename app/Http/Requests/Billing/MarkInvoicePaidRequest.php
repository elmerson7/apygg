<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class MarkInvoicePaidRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:190'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
