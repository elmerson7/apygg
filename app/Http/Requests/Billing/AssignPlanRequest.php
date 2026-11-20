<?php

namespace App\Http\Requests\Billing;

use App\Enums\BillingInterval;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/** Asignación de un plan (también privado) a un tenant por la plataforma: motivo obligatorio. */
class AssignPlanRequest extends BaseFormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function billingInterval(): BillingInterval
    {
        return BillingInterval::from((string) $this->validated('interval'));
    }
}
