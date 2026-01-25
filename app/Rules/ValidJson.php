<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para JSON
 * 
 * Valida que el valor sea un JSON válido y opcionalmente valida su estructura.
 */
class ValidJson implements ValidationRule
{
    /**
     * Esquema JSON esperado (opcional)
     */
    protected ?array $schema = null;

    /**
     * Validar que sea un array
     */
    protected bool $requireArray = false;

    /**
     * Validar que sea un objeto
     */
    protected bool $requireObject = false;

    /**
     * Crear instancia con esquema específico
     */
    public static function withSchema(array $schema): self
    {
        $rule = new self();
        $rule->schema = $schema;
        return $rule;
    }

    /**
     * Crear instancia que requiere array
     */
    public static function array(): self
    {
        $rule = new self();
        $rule->requireArray = true;
        return $rule;
    }

    /**
     * Crear instancia que requiere objeto
     */
    public static function object(): self
    {
        $rule = new self();
        $rule->requireObject = true;
        return $rule;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Si es string, intentar decodificar
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail('El campo :attribute debe ser un JSON válido.');
                return;
            }

            $value = $decoded;
        }

        // Validar tipo requerido
        if ($this->requireArray && !is_array($value)) {
            $fail('El campo :attribute debe ser un array JSON.');
            return;
        }

        if ($this->requireObject && !is_object($value) && !is_array($value)) {
            $fail('El campo :attribute debe ser un objeto JSON.');
            return;
        }

        // Validar esquema si se proporciona
        if ($this->schema !== null && is_array($value)) {
            $this->validateSchema($value, $this->schema, $attribute, $fail);
        }
    }

    /**
     * Validar estructura según esquema
     */
    protected function validateSchema(array $data, array $schema, string $attribute, Closure $fail): void
    {
        foreach ($schema as $key => $expectedType) {
            if (!isset($data[$key])) {
                $fail("El campo :attribute debe contener la clave '{$key}'.");
                return;
            }

            $actualType = gettype($data[$key]);
            if ($actualType !== $expectedType) {
                $fail("El campo :attribute.{$key} debe ser de tipo {$expectedType}.");
            }
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe ser un JSON válido.';
    }
}
