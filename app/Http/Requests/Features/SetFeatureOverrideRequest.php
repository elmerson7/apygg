<?php

namespace App\Http\Requests\Features;

use App\Http\Requests\BaseFormRequest;

class SetFeatureOverrideRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
