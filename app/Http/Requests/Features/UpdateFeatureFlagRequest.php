<?php

namespace App\Http\Requests\Features;

use App\Http\Requests\BaseFormRequest;

class UpdateFeatureFlagRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'rollout_by' => ['sometimes', 'string', 'in:user,tenant'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
