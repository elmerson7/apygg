<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

class EnableMaintenanceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:500'],
            'until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
