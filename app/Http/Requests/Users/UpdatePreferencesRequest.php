<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * UpdatePreferencesRequest
 *
 * Preferencias del usuario en sesión (PUT /user/preferences).
 */
class UpdatePreferencesRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theme' => ['sometimes', 'string', 'in:light,dark'],
            'lang' => ['sometimes', 'string', 'in:es,en'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'notifications.email' => ['sometimes', 'boolean'],
            'notifications.push' => ['sometimes', 'boolean'],
        ];
    }
}
