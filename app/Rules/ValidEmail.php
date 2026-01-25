<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Regla de validación para email
 * 
 * Valida formato de email y opcionalmente verifica dominio válido.
 */
class ValidEmail implements ValidationRule
{
    /**
     * Verificar dominio válido
     */
    protected bool $checkDomain = false;

    /**
     * Dominios permitidos (si se especifica)
     */
    protected ?array $allowedDomains = null;

    /**
     * Crear instancia con verificación de dominio
     */
    public static function withDomainCheck(bool $check = true): self
    {
        $rule = new self();
        $rule->checkDomain = $check;
        return $rule;
    }

    /**
     * Crear instancia con dominios permitidos
     */
    public static function allowedDomains(array $domains): self
    {
        $rule = new self();
        $rule->allowedDomains = $domains;
        $rule->checkDomain = true;
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

        // Validar formato básico de email
        $validator = Validator::make(
            [$attribute => $value],
            [$attribute => 'email']
        );

        if ($validator->fails()) {
            $fail('El campo :attribute debe ser un correo electrónico válido.');
            return;
        }

        // Verificar dominio si está habilitado
        if ($this->checkDomain) {
            $domain = substr(strrchr($value, '@'), 1);
            
            if ($this->allowedDomains !== null) {
                if (!in_array(strtolower($domain), array_map('strtolower', $this->allowedDomains))) {
                    $fail('El campo :attribute debe tener un dominio permitido.');
                    return;
                }
            } else {
                // Verificar que el dominio tenga MX record o A record
                if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
                    $fail('El campo :attribute debe tener un dominio válido.');
                }
            }
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe ser un correo electrónico válido.';
    }
}
