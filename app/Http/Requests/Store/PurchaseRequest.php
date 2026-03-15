<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\BaseFormRequest;

class PurchaseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'string', 'in:wallet,stripe'],
            'stripe_token' => ['required_if:payment_method,stripe', 'nullable', 'string'],
        ];
    }

    protected function getCustomAttributes(): array
    {
        return [
            'product_id' => 'producto',
            'payment_method' => 'método de pago',
            'stripe_token' => 'token de pago',
        ];
    }
}
