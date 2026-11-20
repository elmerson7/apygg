<?php

namespace App\Http\Requests\Billing;

use App\Enums\BillingInterval;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'max:64'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'interval' => ['required', 'string', Rule::enum(BillingInterval::class)],
            // Token que la pasarela entregó al front (nunca datos de tarjeta).
            'payment_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function billingInterval(): BillingInterval
    {
        return BillingInterval::from((string) $this->validated('interval'));
    }
}
