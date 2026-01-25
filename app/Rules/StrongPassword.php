<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para contraseña fuerte
 *
 * Valida que la contraseña cumpla con requisitos de seguridad.
 */
class StrongPassword implements ValidationRule
{
    /**
     * Longitud mínima
     */
    protected int $minLength = 8;

    /**
     * Requerir mayúsculas
     */
    protected bool $requireUppercase = true;

    /**
     * Requerir minúsculas
     */
    protected bool $requireLowercase = true;

    /**
     * Requerir números
     */
    protected bool $requireNumbers = true;

    /**
     * Requerir caracteres especiales
     */
    protected bool $requireSpecial = true;

    /**
     * Crear instancia con configuración personalizada
     */
    public static function make(
        int $minLength = 8,
        bool $requireUppercase = true,
        bool $requireLowercase = true,
        bool $requireNumbers = true,
        bool $requireSpecial = true
    ): self {
        $rule = new self;
        $rule->minLength = $minLength;
        $rule->requireUppercase = $requireUppercase;
        $rule->requireLowercase = $requireLowercase;
        $rule->requireNumbers = $requireNumbers;
        $rule->requireSpecial = $requireSpecial;

        return $rule;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El campo :attribute debe ser una cadena de texto.');

            return;
        }

        $errors = [];

        // Validar longitud mínima
        if (strlen($value) < $this->minLength) {
            $errors[] = "al menos {$this->minLength} caracteres";
        }

        // Validar mayúsculas
        if ($this->requireUppercase && ! preg_match('/[A-Z]/', $value)) {
            $errors[] = 'una letra mayúscula';
        }

        // Validar minúsculas
        if ($this->requireLowercase && ! preg_match('/[a-z]/', $value)) {
            $errors[] = 'una letra minúscula';
        }

        // Validar números
        if ($this->requireNumbers && ! preg_match('/[0-9]/', $value)) {
            $errors[] = 'un número';
        }

        // Validar caracteres especiales
        if ($this->requireSpecial && ! preg_match('/[^A-Za-z0-9]/', $value)) {
            $errors[] = 'un carácter especial';
        }

        if (! empty($errors)) {
            $requirements = implode(', ', $errors);
            $fail("El campo :attribute debe contener {$requirements}.");
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe ser una contraseña segura.';
    }
}
