<?php

namespace App\Rules;

use App\Models\Blacklist;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NotBlacklisted
 *
 * Regla de validación que rechaza correos o documentos en la lista de
 * bloqueados. Pensada para el registro y para los formularios públicos.
 *
 * El mensaje es deliberadamente vago: decirle a alguien que está en una lista
 * negra le indica exactamente qué cambiar para saltársela.
 *
 * Uso:
 *   'email' => ['required', 'email', new NotBlacklisted('email')],
 *   'dni' => ['required', 'string', new NotBlacklisted('identity_document')],
 */
class NotBlacklisted implements ValidationRule
{
    public function __construct(
        private readonly string $field = 'email'
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $blocked = $this->field === 'email'
            ? Blacklist::isBlocked(email: $value)
            : Blacklist::isBlocked(identityDocument: $value);

        if ($blocked) {
            $fail('No es posible continuar con los datos proporcionados.');
        }
    }
}
