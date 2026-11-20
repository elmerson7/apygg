<?php

namespace App\Http\Requests\Billing;

use App\Enums\BillingInterval;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends BaseFormRequest
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
        ];
    }

    public function billingInterval(): BillingInterval
    {
        return BillingInterval::from((string) $this->validated('interval'));
    }
}
