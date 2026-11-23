<?php

namespace App\Support\Money;

use JsonSerializable;
use NumberFormatter;

/**
 * Importe de dinero inmutable: entero en la unidad menor + código ISO 4217. Nunca float.
 *
 * Toda la aritmética es entera o con bcmath (cadenas) y se redondea UNA sola vez, con la regla que
 * elige quien llama (`Rounding`). Sin estado estático (Octane). Ver docs/money.md.
 */
final readonly class Money implements JsonSerializable
{
    public string $currency;

    public function __construct(public int $amountMinor, string $currency)
    {
        $this->currency = self::normalizeCurrency($currency);
    }

    public static function of(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * "12.34" PEN -> 1234. Punto decimal, sin separador de miles, signo opcional. Más decimales de los
     * que admite la moneda es un error (InvalidMoney) salvo que se pase `$rounding`.
     */
    public static function fromDecimalString(string $amount, string $currency, ?Rounding $rounding = null): self
    {
        $currency = self::normalizeCurrency($currency);
        $exponent = self::exponent($currency);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new InvalidMoney("Importe decimal no válido: [{$amount}].");
        }

        $fraction = $m[3] ?? '';
        $negative = $m[1] === '-';

        if (strlen($fraction) > $exponent) {
            if ($rounding === null) {
                throw new InvalidMoney("[{$amount}] tiene más decimales de los que admite {$currency} ({$exponent}).");
            }

            $decimal = ($negative ? '-' : '').$m[2].'.'.$fraction;
            assert(is_numeric($decimal));
            $scaled = self::bcRoundToInt(bcmul($decimal, bcpow('10', (string) $exponent), strlen($fraction)), $rounding);

            return new self(self::toInt($scaled), $currency);
        }

        $digits = $m[2].str_pad($fraction, $exponent, '0');

        $minor = ($negative ? '-' : '').ltrim($digits, '0') ?: '0';
        assert(is_numeric($minor));

        return new self(self::toInt($minor), $currency);
    }

    /** "12.34"; "-0.05"; "1500" (sin decimales para JPY). */
    public function toDecimalString(): string
    {
        $exponent = self::exponent($this->currency);
        $abs = ltrim((string) $this->amountMinor, '-');
        $sign = $this->amountMinor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$abs;
        }

        $abs = str_pad($abs, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$exponent).'.'.substr($abs, -$exponent);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::toInt(bcadd((string) $this->amountMinor, (string) $other->amountMinor)), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::toInt(bcsub((string) $this->amountMinor, (string) $other->amountMinor)), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::toInt(bcmul((string) $this->amountMinor, '-1')), $this->currency);
    }

    public function abs(): self
    {
        return $this->amountMinor < 0 ? $this->negate() : $this;
    }

    /**
     * Multiplica por un factor entero o decimal en cadena ("1.5", "0.18"). Un float no entra (tipo).
     * Redondea una vez al final con `$rounding` a la unidad menor.
     */
    public function multiply(int|string $factor, Rounding $rounding): self
    {
        return new self(self::toInt(self::bcRoundToInt(bcmul((string) $this->amountMinor, self::checkDecimal($factor), 12), $rounding)), $this->currency);
    }

    /** `importe x porcentaje / 100`, redondeado una sola vez: `percentage('18', HalfUp)` para un IGV. */
    public function percentage(int|string $percent, Rounding $rounding): self
    {
        $product = bcmul((string) $this->amountMinor, self::checkDecimal($percent), 12);

        return new self(self::toInt(self::bcRoundToInt(bcdiv($product, '100', 12), $rounding)), $this->currency);
    }

    /**
     * Reparte el importe en partes proporcionales a `$ratios` (enteros no negativos, al menos uno > 0)
     * SIN perder unidades menores: la suma de las partes es exactamente el importe. El resto se da de
     * a una unidad menor a las primeras partes (en orden). Con importe negativo se reparte el valor
     * absoluto y se niegan las partes.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidMoney('allocate() necesita al menos una proporción.');
        }

        $total = 0;
        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidMoney('Las proporciones de allocate() no pueden ser negativas.');
            }
            $total += $ratio;
        }

        if ($total <= 0) {
            throw new InvalidMoney('Alguna proporción de allocate() debe ser mayor que cero.');
        }

        $abs = abs($this->amountMinor);
        $shares = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = self::toInt(bcdiv(bcmul((string) $abs, (string) $ratio), (string) $total, 0));
            $shares[] = $share;
            $allocated += $share;
        }

        $remainder = $abs - $allocated;
        foreach (array_keys($shares) as $i) {
            if ($remainder === 0) {
                break;
            }
            if ($ratios[$i] > 0) {
                $shares[$i]++;
                $remainder--;
            }
        }

        $sign = $this->amountMinor < 0 ? -1 : 1;

        return array_map(fn (int $s): self => new self($s * $sign, $this->currency), $shares);
    }

    /**
     * Reparte en `$parts` partes iguales (el resto, una unidad menor a las primeras).
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidMoney('split() necesita al menos una parte.');
        }

        return $this->allocate(array_fill(0, $parts, 1));
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    /** Mismo importe y misma moneda (a diferencia de `compare`, no lanza con monedas distintas). */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    /**
     * Texto para mostrar según el locale ("es_PE" -> "S/ 12,34"). SOLO presentación: para mostrar se
     * convierte a número de coma flotante (NumberFormatter no admite cadenas), así que no uses esta
     * cadena para calcular. El `(float)` de abajo solo alimenta a `NumberFormatter`: su único uso es
     * `InvoiceService` al pintar el documento; ningún cálculo, comparación ni persistencia lo toca (todo es
     * `amountMinor` entero). La API intercambia `amount_minor` + `currency`.
     */
    public function format(string $locale = 'en_US'): string
    {
        $exponent = self::exponent($this->currency);
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $exponent);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $exponent);

        $value = $exponent === 0 ? $this->amountMinor : (float) $this->toDecimalString();

        return (string) $formatter->formatCurrency($value, $this->currency);
    }

    /** @return array{amount_minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }

    public static function exponent(string $currency): int
    {
        $map = config('currencies.exponents');
        $code = strtoupper($currency);

        if (! is_array($map) || ! array_key_exists($code, $map)) {
            throw UnsupportedCurrency::for($currency);
        }

        return (int) $map[$code];
    }

    private static function normalizeCurrency(string $currency): string
    {
        $code = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidMoney("Código de moneda no válido: [{$currency}].");
        }

        self::exponent($code);

        return $code;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /** @return numeric-string */
    private static function checkDecimal(int|string $value): string
    {
        $value = (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidMoney("Factor decimal no válido: [{$value}].");
        }

        assert(is_numeric($value));

        return $value;
    }

    /**
     * Redondea una cadena decimal (bcmath) a entero según la regla.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function bcRoundToInt(string $value, Rounding $rounding): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        [$int, $frac] = array_pad(explode('.', $abs, 2), 2, '');
        assert(is_numeric($int));
        $frac = rtrim($frac, '0');

        if ($frac === '') {
            $whole = ($negative && $int !== '0' ? '-' : '').$int;
            assert(is_numeric($whole));

            return $whole;
        }

        $first = (int) $frac[0];
        $restIsZero = rtrim(substr($frac, 1), '0') === '';

        $up = match ($rounding) {
            Rounding::Down => false,
            Rounding::Up => true,
            Rounding::HalfUp => $first >= 5,
            Rounding::HalfEven => $first > 5 || ($first === 5 && (! $restIsZero || ((int) substr($int, -1)) % 2 === 1)),
        };

        $result = $up ? bcadd($int, '1') : $int;

        $rounded = ($negative && $result !== '0' ? '-' : '').$result;
        assert(is_numeric($rounded));

        return $rounded;
    }

    /** @param  numeric-string  $value */
    private static function toInt(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX) > 0 || bccomp($value, (string) PHP_INT_MIN) < 0) {
            throw new InvalidMoney('El importe sale del rango de un entero de 64 bits.');
        }

        return (int) $value;
    }
}
