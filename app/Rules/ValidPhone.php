<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para teléfono
 * 
 * Valida formato de número telefónico (internacional o nacional).
 */
class ValidPhone implements ValidationRule
{
    /**
     * Formato permitido: 'international', 'national', 'both'
     */
    protected string $format = 'both';

    /**
     * Código de país por defecto (para formato nacional)
     */
    protected ?string $countryCode = null;

    /**
     * Crear instancia con formato específico
     */
    public static function format(string $format, ?string $countryCode = null): self
    {
        $rule = new self();
        $rule->format = $format;
        $rule->countryCode = $countryCode;
        return $rule;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) && !is_numeric($value)) {
            $fail('El campo :attribute debe ser un número telefónico válido.');
            return;
        }

        $phone = (string) $value;
        
        // Remover espacios, guiones, paréntesis
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        // Validar según formato
        if ($this->format === 'international' || $this->format === 'both') {
            // Formato internacional: +[código país][número]
            if (preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
                return; // Válido
            }
        }

        if ($this->format === 'national' || $this->format === 'both') {
            // Formato nacional: solo números (10-15 dígitos)
            if (preg_match('/^\d{10,15}$/', $phone)) {
                return; // Válido
            }
        }

        $fail('El campo :attribute debe ser un número telefónico válido.');
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        $formatText = match($this->format) {
            'international' => 'internacional',
            'national' => 'nacional',
            default => 'válido',
        };

        return "El campo :attribute debe ser un número telefónico {$formatText}.";
    }
}
