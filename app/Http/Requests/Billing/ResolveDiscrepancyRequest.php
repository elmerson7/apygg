<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class ResolveDiscrepancyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
