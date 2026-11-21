<?php

namespace App\Rules;

use App\Traits\ClientGeneratedId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * UUID generado por el cliente (módulo `offline-sync`): versiones de `offline-sync.client_id_versions`.
 */
class ClientGeneratedUuid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ClientGeneratedId::isValidClientId($value)) {
            $fail('El :attribute debe ser un UUID válido (se recomienda v7).');
        }
    }
}
