<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class CancelSubscriptionRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['immediately' => ['sometimes', 'boolean']];
    }

    public function immediately(): bool
    {
        return (bool) $this->validated('immediately', false);
    }
}
