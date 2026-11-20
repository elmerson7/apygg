<?php

namespace App\Http\Requests\Privacy;

use App\Http\Requests\BaseFormRequest;

class RequestAccountDeletionRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Sin recortar ni transformar: la contraseña se compara tal cual.
            'password' => ['required', 'string', 'max:255'],
            'second_factor' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
