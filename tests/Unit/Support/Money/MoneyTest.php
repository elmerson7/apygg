<?php

use App\Support\Money\CurrencyMismatch;
use App\Support\Money\InvalidMoney;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use App\Support\Money\UnsupportedCurrency;

test('construye desde unidades menores y normaliza la moneda a mayúsculas', function () {
    $m = Money::of(1234, 'pen');

    expect($m->amountMinor)->toBe(1234)->and($m->currency)->toBe('PEN');
});

test('rechaza monedas mal formadas o no admitidas', function () {
    expect(fn () => Money::of(1, 'EURO'))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(1, 'XXX'))->toThrow(UnsupportedCurrency::class);
});

test('fromDecimalString y toDecimalString son inversos con 2, 0 y 3 decimales', function (string $dec, string $cur, int $minor) {
    expect(Money::fromDecimalString($dec, $cur)->amountMinor)->toBe($minor);
    expect(Money::of($minor, $cur)->toDecimalString())->toBe($dec);
})->with([
    ['12.34', 'PEN', 1234],
    ['0.05', 'EUR', 5],
    ['-0.05', 'EUR', -5],
    ['-12.34', 'USD', -1234],
    ['1500', 'JPY', 1500],
    ['1500', 'CLP', 1500],
    ['1.234', 'KWD', 1234],
    ['0.00', 'COP', 0],
]);

test('fromDecimalString completa decimales ausentes y rechaza formatos raros', function () {
    expect(Money::fromDecimalString('12', 'PEN')->amountMinor)->toBe(1200);
    expect(Money::fromDecimalString('12.3', 'PEN')->amountMinor)->toBe(1230);

    foreach (['', '1,234.50', '1e3', '.5', '12.', ' 1', 'abc', '--1'] as $bad) {
        expect(fn () => Money::fromDecimalString($bad, 'PEN'))->toThrow(InvalidMoney::class);
    }
});

test('fromDecimalString con más decimales de los admitidos falla salvo que se indique redondeo', function () {
    expect(fn () => Money::fromDecimalString('12.345', 'PEN'))->toThrow(InvalidMoney::class);
    expect(fn () => Money::fromDecimalString('12.5', 'JPY'))->toThrow(InvalidMoney::class);

    expect(Money::fromDecimalString('12.345', 'PEN', Rounding::HalfUp)->amountMinor)->toBe(1235);
    expect(Money::fromDecimalString('12.345', 'PEN', Rounding::HalfEven)->amountMinor)->toBe(1234);
    expect(Money::fromDecimalString('12.5', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(13);
    expect(Money::fromDecimalString('-12.345', 'PEN', Rounding::HalfUp)->amountMinor)->toBe(-1235);
});

test('rechaza importes fuera del rango de 64 bits', function () {
    expect(fn () => Money::fromDecimalString('99999999999999999999.99', 'PEN'))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(PHP_INT_MAX, 'PEN')->add(Money::of(1, 'PEN')))->toThrow(InvalidMoney::class);
});

test('suma y resta en la misma moneda sin perder precisión', function () {
    $a = Money::fromDecimalString('0.10', 'EUR');
    $b = Money::fromDecimalString('0.20', 'EUR');

    expect($a->add($b)->toDecimalString())->toBe('0.30');
    expect($a->subtract($b)->toDecimalString())->toBe('-0.10');
    expect($a->add($b)->equals(Money::fromDecimalString('0.30', 'EUR')))->toBeTrue();
});

test('sumar, restar o comparar monedas distintas lanza CurrencyMismatch', function () {
    $pen = Money::of(100, 'PEN');
    $usd = Money::of(100, 'USD');

    expect(fn () => $pen->add($usd))->toThrow(CurrencyMismatch::class);
    expect(fn () => $pen->subtract($usd))->toThrow(CurrencyMismatch::class);
    expect(fn () => $pen->compare($usd))->toThrow(CurrencyMismatch::class);
    expect($pen->equals($usd))->toBeFalse();
});

test('multiplica con redondeo explícito en el límite x.5', function () {
    $m = Money::of(5, 'EUR'); // 0,05

    expect($m->multiply('0.5', Rounding::HalfUp)->amountMinor)->toBe(3);    // 2,5 -> 3
    expect($m->multiply('0.5', Rounding::HalfEven)->amountMinor)->toBe(2);  // 2,5 -> 2
    expect($m->multiply('0.5', Rounding::Down)->amountMinor)->toBe(2);
    expect($m->multiply('0.5', Rounding::Up)->amountMinor)->toBe(3);
    expect(Money::of(15, 'EUR')->multiply('0.5', Rounding::HalfEven)->amountMinor)->toBe(8); // 7,5 -> 8 (par)
    expect(Money::of(-5, 'EUR')->multiply('0.5', Rounding::HalfUp)->amountMinor)->toBe(-3);
    expect(Money::of(-5, 'EUR')->multiply('0.5', Rounding::Down)->amountMinor)->toBe(-2);
    expect($m->multiply(3, Rounding::HalfUp)->amountMinor)->toBe(15);
});

test('porcentaje: IGV 18 % de 100,00 y de importes con resto, redondeado una sola vez', function () {
    expect(Money::fromDecimalString('100.00', 'PEN')->percentage('18', Rounding::HalfUp)->toDecimalString())->toBe('18.00');
    // 0,05 * 10 % = 0,005 -> 0,01 (HalfUp) / 0,00 (HalfEven)
    expect(Money::of(5, 'PEN')->percentage('10', Rounding::HalfUp)->amountMinor)->toBe(1);
    expect(Money::of(5, 'PEN')->percentage('10', Rounding::HalfEven)->amountMinor)->toBe(0);
    // 9,99 * 18 % = 1,7982 -> 1,80
    expect(Money::of(999, 'PEN')->percentage('18', Rounding::HalfUp)->amountMinor)->toBe(180);
    expect(Money::of(10_000, 'JPY')->percentage('8', Rounding::HalfUp)->amountMinor)->toBe(800);
});

test('un factor no numérico se rechaza', function () {
    expect(fn () => Money::of(1, 'PEN')->multiply('1,5', Rounding::HalfUp))->toThrow(InvalidMoney::class);
});

test('allocate reparte sin perder céntimos y la suma es exacta', function () {
    $parts = Money::of(100, 'EUR')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $p) => $p->amountMinor, $parts))->toBe([34, 33, 33]);

    foreach ([[1, 2, 3], [70, 30], [1, 1, 1, 1, 1, 1, 1], [0, 5, 0, 5], [3]] as $ratios) {
        foreach ([0, 1, 99, 100, 1001, 999_999_999_999] as $amount) {
            $sum = array_sum(array_map(fn (Money $p) => $p->amountMinor, Money::of($amount, 'USD')->allocate($ratios)));
            expect($sum)->toBe($amount);
        }
    }
});

test('allocate no da resto a las partes con proporción cero y maneja negativos', function () {
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(5, 'EUR')->allocate([0, 1, 1])))->toBe([0, 3, 2]);
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(-100, 'EUR')->allocate([1, 1, 1])))->toBe([-34, -33, -33]);
});

test('allocate y split validan sus argumentos', function () {
    expect(fn () => Money::of(1, 'EUR')->allocate([]))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(1, 'EUR')->allocate([0, 0]))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(1, 'EUR')->allocate([1, -1]))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(1, 'EUR')->split(0))->toThrow(InvalidMoney::class);
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(10, 'JPY')->split(4)))->toBe([3, 3, 2, 2]);
});

test('comparaciones y signos', function () {
    $a = Money::of(100, 'PEN');
    $b = Money::of(200, 'PEN');

    expect($a->lessThan($b))->toBeTrue()
        ->and($b->greaterThan($a))->toBeTrue()
        ->and($a->compare($a))->toBe(0)
        ->and($a->negate()->isNegative())->toBeTrue()
        ->and($a->negate()->abs()->equals($a))->toBeTrue()
        ->and(Money::zero('PEN')->isZero())->toBeTrue()
        ->and($a->isPositive())->toBeTrue();
});

test('toma el exponente de config/currencies.php', function () {
    expect(Money::exponent('JPY'))->toBe(0)
        ->and(Money::exponent('CLP'))->toBe(0)
        ->and(Money::exponent('PEN'))->toBe(2)
        ->and(Money::exponent('KWD'))->toBe(3);

    foreach (['PEN', 'USD', 'EUR', 'MXN', 'COP', 'CLP', 'ARS', 'BRL', 'GBP', 'JPY'] as $c) {
        expect(config('currencies.exponents'))->toHaveKey($c);
    }
});

test('formatea por locale con los decimales de la moneda', function () {
    expect(Money::of(123456, 'EUR')->format('es_ES'))->toContain('1.234,56')
        ->and(Money::of(123456, 'USD')->format('en_US'))->toBe('$1,234.56')
        ->and(Money::of(1500, 'JPY')->format('en_US'))->toContain('1,500')
        ->and(Money::of(1500, 'JPY')->format('en_US'))->not->toContain('.');
});

test('se serializa a JSON como entero + moneda', function () {
    expect(json_encode(Money::of(1999, 'EUR')))->toBe('{"amount_minor":1999,"currency":"EUR"}');
});
