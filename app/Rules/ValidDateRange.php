<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para rango de fechas
 *
 * Valida que una fecha esté dentro de un rango permitido.
 */
class ValidDateRange implements ValidationRule
{
    /**
     * Fecha mínima permitida
     */
    protected ?Carbon $minDate = null;

    /**
     * Fecha máxima permitida
     */
    protected ?Carbon $maxDate = null;

    /**
     * Formato de fecha esperado
     */
    protected string $format = 'Y-m-d';

    /**
     * Crear instancia con rango específico
     */
    public static function between(?Carbon $minDate = null, ?Carbon $maxDate = null, string $format = 'Y-m-d'): self
    {
        $rule = new self;
        $rule->minDate = $minDate;
        $rule->maxDate = $maxDate;
        $rule->format = $format;

        return $rule;
    }

    /**
     * Crear instancia con fecha mínima
     */
    public static function min(Carbon $minDate, string $format = 'Y-m-d'): self
    {
        return self::between($minDate, null, $format);
    }

    /**
     * Crear instancia con fecha máxima
     */
    public static function max(Carbon $maxDate, string $format = 'Y-m-d'): self
    {
        return self::between(null, $maxDate, $format);
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El campo :attribute debe ser una fecha válida.');

            return;
        }

        try {
            $date = Carbon::createFromFormat($this->format, $value);
        } catch (\Exception $e) {
            $fail("El campo :attribute debe tener el formato {$this->format}.");

            return;
        }

        // Validar fecha mínima
        if ($this->minDate !== null && $date->lt($this->minDate)) {
            $minDateFormatted = $this->minDate->format($this->format);
            $fail("El campo :attribute debe ser posterior o igual a {$minDateFormatted}.");

            return;
        }

        // Validar fecha máxima
        if ($this->maxDate !== null && $date->gt($this->maxDate)) {
            $maxDateFormatted = $this->maxDate->format($this->format);
            $fail("El campo :attribute debe ser anterior o igual a {$maxDateFormatted}.");
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El campo :attribute debe estar dentro del rango de fechas permitido.';
    }
}
