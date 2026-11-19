<?php

namespace App\Casts;

use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Guarda un `Money` en DOS columnas: `<atributo>_minor` (bigint) + `currency` (char(3)).
 *
 *   protected function casts(): array { return ['total' => MoneyCast::class]; }              // total_minor + currency
 *   protected function casts(): array { return ['tax' => MoneyCast::class.':tax_currency']; } // tax_minor + tax_currency
 *
 * El atributo puede llamarse también `total_minor` (se usa tal cual). Con `*_minor` NULL el valor es null.
 * Ver docs/money.md.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
class MoneyCast implements CastsAttributes, SerializesCastableAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minorColumn = $this->minorColumn($key);
        $minor = $attributes[$minorColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($minor === null || $currency === null) {
            return null;
        }

        // PDO devuelve bigint como string en algunos drivers: se castea aquí.
        return new Money((int) $minor, (string) $currency);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $minorColumn = $this->minorColumn($key);

        if ($value === null) {
            return [$minorColumn => null, $this->currencyColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("El atributo [{$key}] espera un Money (o null).");
        }

        return [$minorColumn => $value->amountMinor, $this->currencyColumn => $value->currency];
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof Money ? $value->jsonSerialize() : null;
    }

    private function minorColumn(string $key): string
    {
        return str_ends_with($key, '_minor') ? $key : $key.'_minor';
    }
}
