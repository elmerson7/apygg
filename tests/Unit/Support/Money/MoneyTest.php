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

test('formatear solo presenta: no altera el entero en unidad menor ni el resultado de los cálculos', function () {
    $m = Money::of(123456789, 'USD');
    $m->format('en_US');

    expect($m->amountMinor)->toBe(123456789)
        ->and($m->add(Money::of(1, 'USD'))->amountMinor)->toBe(123456790)
        ->and($m->format('en_US'))->toBe('$1,234,567.89');
});

test('toDecimalString conserva el signo con el menor importe negativo y con monedas sin decimales', function () {
    expect(Money::of(-1, 'PEN')->toDecimalString())->toBe('-0.01')
        ->and(Money::of(1, 'PEN')->toDecimalString())->toBe('0.01')
        ->and(Money::of(0, 'PEN')->toDecimalString())->toBe('0.00')
        ->and(Money::of(-1500, 'JPY')->toDecimalString())->toBe('-1500')
        ->and(Money::of(-1, 'KWD')->toDecimalString())->toBe('-0.001');
});

test('abs devuelve el valor absoluto incluso del menor importe negativo', function () {
    expect(Money::of(-1, 'PEN')->abs()->amountMinor)->toBe(1)
        ->and(Money::of(-250, 'PEN')->abs()->amountMinor)->toBe(250)
        ->and(Money::of(250, 'PEN')->abs()->amountMinor)->toBe(250)
        ->and(Money::of(0, 'PEN')->abs()->amountMinor)->toBe(0);
});

test('los signos y comparaciones distinguen el cero y el menor importe', function () {
    $zero = Money::of(0, 'PEN');
    $one = Money::of(1, 'PEN');
    $minusOne = Money::of(-1, 'PEN');

    expect($zero->isPositive())->toBeFalse()
        ->and($zero->isNegative())->toBeFalse()
        ->and($one->isPositive())->toBeTrue()
        ->and($one->isNegative())->toBeFalse()
        ->and($minusOne->isNegative())->toBeTrue()
        ->and($minusOne->isPositive())->toBeFalse()
        ->and($one->greaterThan($one))->toBeFalse()
        ->and($one->lessThan($one))->toBeFalse()
        ->and($one->greaterThan($zero))->toBeTrue()
        ->and($zero->greaterThan($one))->toBeFalse()
        ->and($zero->lessThan($one))->toBeTrue()
        ->and($one->lessThan($zero))->toBeFalse()
        ->and($zero->greaterThan($minusOne))->toBeTrue()
        ->and($minusOne->lessThan($zero))->toBeTrue();
});

test('acepta exactamente los límites de 64 bits y rechaza uno más', function () {
    expect(Money::fromDecimalString('92233720368547758.07', 'PEN')->amountMinor)->toBe(PHP_INT_MAX)
        ->and(Money::fromDecimalString('-92233720368547758.08', 'PEN')->amountMinor)->toBe(PHP_INT_MIN);

    expect(fn () => Money::fromDecimalString('92233720368547758.08', 'PEN'))->toThrow(InvalidMoney::class);
    expect(fn () => Money::fromDecimalString('-92233720368547758.09', 'PEN'))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(PHP_INT_MIN, 'PEN')->subtract(Money::of(1, 'PEN')))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(PHP_INT_MIN, 'PEN')->negate())->toThrow(InvalidMoney::class);
});

test('multiplicar por un entero exacto no redondea con ninguna regla, también en negativo', function (Rounding $rounding) {
    expect(Money::of(5, 'EUR')->multiply(2, $rounding)->amountMinor)->toBe(10)
        ->and(Money::of(5, 'EUR')->multiply(-2, $rounding)->amountMinor)->toBe(-10)
        ->and(Money::of(5, 'EUR')->multiply('2.50', $rounding)->amountMinor)->toBe(in_array($rounding, [Rounding::Up, Rounding::HalfUp], true) ? 13 : 12)
        ->and(Money::of(0, 'EUR')->multiply(-3, $rounding)->amountMinor)->toBe(0);
})->with([Rounding::Down, Rounding::Up, Rounding::HalfUp, Rounding::HalfEven]);

test('HalfUp redondea hacia arriba desde 0,5 y hacia abajo por debajo', function () {
    expect(Money::fromDecimalString('0.4', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('0.49', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('0.5', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(1)
        ->and(Money::fromDecimalString('-0.4', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('-0.5', 'JPY', Rounding::HalfUp)->amountMinor)->toBe(-1);
});

test('HalfEven redondea al par en el empate y se fija en los decimales siguientes', function () {
    expect(Money::fromDecimalString('0.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('1.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(2)
        ->and(Money::fromDecimalString('2.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(2)
        ->and(Money::fromDecimalString('3.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(4)
        ->and(Money::fromDecimalString('12.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(12)
        ->and(Money::fromDecimalString('13.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(14)
        ->and(Money::fromDecimalString('2.51', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(3)
        ->and(Money::fromDecimalString('2.501', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(3)
        ->and(Money::fromDecimalString('2.49', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(2)
        ->and(Money::fromDecimalString('2.6', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(3)
        ->and(Money::fromDecimalString('2.4', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(2)
        ->and(Money::fromDecimalString('-2.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(-2)
        ->and(Money::fromDecimalString('-3.5', 'JPY', Rounding::HalfEven)->amountMinor)->toBe(-4);
});

test('Down trunca hacia cero y Up redondea alejándose de cero', function () {
    expect(Money::fromDecimalString('2.9', 'JPY', Rounding::Down)->amountMinor)->toBe(2)
        ->and(Money::fromDecimalString('-2.9', 'JPY', Rounding::Down)->amountMinor)->toBe(-2)
        ->and(Money::fromDecimalString('2.1', 'JPY', Rounding::Up)->amountMinor)->toBe(3)
        ->and(Money::fromDecimalString('-2.1', 'JPY', Rounding::Up)->amountMinor)->toBe(-3)
        ->and(Money::fromDecimalString('2.0', 'JPY', Rounding::Up)->amountMinor)->toBe(2);
});

test('multiplicar y porcentaje conservan 12 decimales de precisión antes de redondear', function () {
    // 12 decimales exactos en el factor: el empate 0,5 + 1e-12 sube con HalfEven.
    expect(Money::of(1, 'EUR')->multiply('0.500000000001', Rounding::HalfEven)->amountMinor)->toBe(1)
        // 13 decimales: el último se descarta a escala 12 y queda empate exacto -> par (0).
        ->and(Money::of(1, 'EUR')->multiply('0.5000000000001', Rounding::HalfEven)->amountMinor)->toBe(0);

    // percentage divide por 100 con escala 12.
    expect(Money::of(1, 'EUR')->percentage('50.0000000001', Rounding::HalfEven)->amountMinor)->toBe(1)
        ->and(Money::of(1, 'EUR')->percentage('50.00000000001', Rounding::HalfEven)->amountMinor)->toBe(0)
        ->and(Money::of(1000, 'EUR')->percentage('18', Rounding::HalfUp)->amountMinor)->toBe(180)
        ->and(Money::of(5, 'EUR')->percentage('10', Rounding::HalfUp)->amountMinor)->toBe(1)
        ->and(Money::of(-5, 'EUR')->percentage(10, Rounding::HalfUp)->amountMinor)->toBe(-1);
});

test('multiplicar y porcentaje rechazan factores no decimales', function () {
    foreach (['1e3', '', 'abc', '1,5', '.5', '1.'] as $bad) {
        expect(fn () => Money::of(1, 'EUR')->multiply($bad, Rounding::Down))->toThrow(InvalidMoney::class);
        expect(fn () => Money::of(1, 'EUR')->percentage($bad, Rounding::Down))->toThrow(InvalidMoney::class);
    }
});

test('allocate rechaza proporciones negativas (también -1) y totales nulos, y acepta un único 1', function () {
    expect(fn () => Money::of(10, 'PEN')->allocate([-1, 5]))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(10, 'PEN')->allocate([]))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(10, 'PEN')->allocate([0, 0]))->toThrow(InvalidMoney::class);

    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(10, 'PEN')->allocate([1])))->toBe([10])
        ->and(array_map(fn (Money $p) => $p->amountMinor, Money::of(10, 'PEN')->allocate([0, 1])))->toBe([0, 10]);
});

test('allocate con importe negativo reparte el valor absoluto y niega las partes, incluido -1', function () {
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(-1, 'PEN')->allocate([1, 1])))->toBe([-1, 0])
        ->and(array_map(fn (Money $p) => $p->amountMinor, Money::of(-10, 'PEN')->allocate([1, 1, 1])))->toBe([-4, -3, -3])
        ->and(array_map(fn (Money $p) => $p->amountMinor, Money::of(0, 'PEN')->allocate([1, 2])))->toBe([0, 0]);
});

test('allocate no da resto a las partes con proporción cero y la suma siempre cuadra', function () {
    $parts = Money::of(101, 'PEN')->allocate([0, 1, 1, 0, 1]);

    expect(array_map(fn (Money $p) => $p->amountMinor, $parts))->toBe([0, 34, 34, 0, 33])
        ->and(array_sum(array_map(fn (Money $p) => $p->amountMinor, $parts)))->toBe(101);
});

test('split acepta una sola parte y rechaza cero o negativas', function () {
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(7, 'PEN')->split(1)))->toBe([7]);
    expect(array_map(fn (Money $p) => $p->amountMinor, Money::of(10, 'PEN')->split(3)))->toBe([4, 3, 3]);
    expect(fn () => Money::of(7, 'PEN')->split(0))->toThrow(InvalidMoney::class);
    expect(fn () => Money::of(7, 'PEN')->split(-1))->toThrow(InvalidMoney::class);
});

test('exponent no distingue mayúsculas y rechaza monedas fuera de la configuración', function () {
    expect(Money::exponent('jpy'))->toBe(0)
        ->and(Money::exponent('kwd'))->toBe(3);
    expect(fn () => Money::exponent('xxx'))->toThrow(UnsupportedCurrency::class);

    config(['currencies.exponents' => null]);
    expect(fn () => Money::exponent('PEN'))->toThrow(UnsupportedCurrency::class);
});

test('format usa siempre los decimales de la moneda configurada como mínimo y máximo', function () {
    config(['currencies.exponents.USD' => 3]);

    expect(Money::of(1230, 'USD')->format('en_US'))->toBe('$1.230')
        ->and(Money::of(1, 'USD')->format('en_US'))->toBe('$0.001')
        ->and(Money::of(1235, 'USD')->format('en_US'))->toBe('$1.235');
});

test('fromDecimalString trata cero con signo y ceros a la izquierda', function () {
    expect(Money::fromDecimalString('0', 'PEN')->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('007.05', 'PEN')->amountMinor)->toBe(705)
        ->and(Money::fromDecimalString('-0.00', 'PEN')->amountMinor)->toBe(0)
        ->and(Money::fromDecimalString('-0.001', 'PEN', Rounding::Down)->amountMinor)->toBe(0);
});
