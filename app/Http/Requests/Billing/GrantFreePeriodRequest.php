<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class GrantFreePeriodRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('billing.free_period_max_days', 365)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
