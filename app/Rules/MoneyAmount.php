<?php

namespace App\Rules;

use App\Support\Money\InvalidMoney;
use App\Support\Money\Money;
use App\Support\Money\UnsupportedCurrency;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Importe decimal en cadena ("12.34") con a lo sumo los decimales de SU moneda (PEN 2, JPY 0).
 * Rechaza floats JSON (12.34 sin comillas), separadores de miles y rangos fuera de 64 bits.
 *
 *   'amount'   => ['required', new MoneyAmount],               // moneda del campo `currency`
 *   'amount'   => ['required', MoneyAmount::currencyField('moneda')],
 *   'amount'   => ['required', MoneyAmount::for('PEN')],       // moneda fija
 *   MoneyAmount::for('PEN')->allowNegative()                   // reembolsos
 */
class MoneyAmount implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    private bool $allowNegative = false;

    final public function __construct(private ?string $currency = null, private string $field = 'currency') {}

    public static function for(string $currency): static
    {
        return new static($currency);
    }

    public static function currencyField(string $field): static
    {
        return new static(null, $field);
    }

    public function allowNegative(): static
    {
        $this->allowNegative = true;

        return $this;
    }

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currency = $this->currency ?? data_get($this->data, $this->field);

        if (! is_string($currency)) {
            $fail('La moneda del importe no está indicada.');

            return;
        }

        if (! is_string($value) && ! is_int($value)) {
            $fail('El campo :attribute debe ser un importe decimal en texto (por ejemplo "12.34").');

            return;
        }

        $value = (string) $value;

        try {
            $money = Money::fromDecimalString($value, $currency);
        } catch (UnsupportedCurrency) {
            $fail('La moneda del importe no es válida.');

            return;
        } catch (InvalidMoney) {
            $exponent = Money::exponent($currency);
            $fail("El campo :attribute debe ser un importe decimal con hasta {$exponent} decimales.");

            return;
        }

        if (! $this->allowNegative && $money->isNegative()) {
            $fail('El campo :attribute no puede ser negativo.');
        }
    }
}
