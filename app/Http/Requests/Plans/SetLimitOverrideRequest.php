<?php

namespace App\Http\Requests\Plans;

use App\Http\Requests\BaseFormRequest;

class SetLimitOverrideRequest extends BaseFormRequest
{
    /**
     * `value` null = ilimitado para ese tenant.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['value' => ['present', 'nullable', 'integer', 'min:0']];
    }
}
