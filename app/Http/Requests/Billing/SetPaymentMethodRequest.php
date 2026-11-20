<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class SetPaymentMethodRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:255']];
    }
}
