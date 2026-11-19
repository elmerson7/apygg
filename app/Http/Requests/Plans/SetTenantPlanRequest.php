<?php

namespace App\Http\Requests\Plans;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SetTenantPlanRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['plan' => ['present', 'nullable', 'string', Rule::in(array_keys((array) config('plans.plans', [])))]];
    }
}
