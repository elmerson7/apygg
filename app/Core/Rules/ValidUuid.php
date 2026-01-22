<?php

namespace App\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para UUID
 * 
 * Valida que el valor sea un UUID válido (v4 por defecto).
 */
class ValidUuid implements ValidationRule
{
    /**
     * Versión de UUID a validar (4 por defecto)
     */
    protected int $version = 4;

    /**
     * Crear instancia con versión específica
     */
    public static function version(int $version): self
    {
        $rule = new self();
        $rule->version = $version;
        return $rule;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('El campo :attribute debe ser una cadena de texto.');
            return;
        }

        // Validar formato básico de UUID
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        
        if (!preg_match($uuidPattern, $value)) {
            $fail('El campo :attribute debe ser un UUID válido.');
            return;
        }

        // Validar versión específica si se requiere
        if ($this->version === 4) {
            $versionChar = substr($value, 14, 1);
            if (!in_array($versionChar, ['8', '9', 'a', 'b'], true)) {
                $fail('El campo :attribute debe ser un UUID versión 4 válido.');
            }
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe ser un UUID válido.';
    }
}
