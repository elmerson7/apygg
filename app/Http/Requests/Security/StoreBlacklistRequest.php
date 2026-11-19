<?php

namespace App\Http\Requests\Security;

use App\Http\Requests\BaseFormRequest;
use Closure;

class StoreBlacklistRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'identity_document' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<Closure>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                // Un bloqueo sin ninguna identidad no bloquea nada.
                if (! $this->filled('user_id') && ! $this->filled('email') && ! $this->filled('identity_document')) {
                    $validator->errors()->add('email', 'Indica al menos un correo, un documento o un usuario');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo del bloqueo es obligatorio',
        ];
    }
}
