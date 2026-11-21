<?php

namespace App\Traits;

use Illuminate\Validation\Rule;

/**
 * Reglas del documento de identidad de un usuario: opcional, único si viene y, solo si el
 * proyecto lo configura (`users.identity_document.regex`), con formato propio. Sin formato de país
 * por defecto.
 */
trait ValidatesIdentityDocument
{
    /**
     * @return list<mixed>
     */
    protected function identityDocumentRules(int|string|null $ignoreUserId = null): array
    {
        $rules = ['sometimes', 'nullable', 'string', 'max:'.(int) config('users.identity_document.max', 50)];

        $regex = (string) config('users.identity_document.regex', '');

        if ($regex !== '') {
            $rules[] = 'regex:'.$regex;
        }

        $rules[] = $ignoreUserId === null
            ? Rule::unique('users', 'identity_document')
            : Rule::unique('users', 'identity_document')->ignore($ignoreUserId);

        return $rules;
    }
}
