<?php

use App\Enums\BillingInterval;
use App\Support\Billing\PeriodCalculator;
use App\Support\Billing\Proration;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

test('el prorrateo a mitad de periodo cobra la diferencia de la mitad restante', function () {
    $start = CarbonImmutable::parse('2026-01-01 00:00:00');
    $end = CarbonImmutable::parse('2026-01-31 00:00:00');
    $now = CarbonImmutable::parse('2026-01-16 00:00:00');   // queda exactamente la mitad

    $p = Proration::calculate(Money::of(2900, 'USD'), Money::of(9900, 'USD'), $start, $end, $now);

    expect($p->ratio)->toBe('0.500000000000')
        ->and($p->credit->amountMinor)->toBe(1450)
        ->and($p->charge->amountMinor)->toBe(4950)
        ->and($p->net->amountMinor)->toBe(3500);
});

test('el neto del prorrateo es exactamente cargo menos crédito aunque haya redondeo', function () {
    $start = CarbonImmutable::parse('2026-03-01 00:00:00');
    $end = CarbonImmutable::parse('2026-04-01 00:00:00');
    $now = CarbonImmutable::parse('2026-03-11 07:13:41');

    $p = Proration::calculate(Money::of(1999, 'EUR'), Money::of(4999, 'EUR'), $start, $end, $now);

    expect($p->net->amountMinor)->toBe($p->charge->amountMinor - $p->credit->amountMinor)
        ->and($p->credit->amountMinor)->toBeInt()
        ->and($p->net->isPositive())->toBeTrue();
});

test('al empezar el periodo el prorrateo es completo y al terminarlo es cero', function () {
    $start = CarbonImmutable::parse('2026-01-01');
    $end = CarbonImmutable::parse('2026-02-01');

    $inicio = Proration::calculate(Money::of(1000, 'USD'), Money::of(3000, 'USD'), $start, $end, $start);
    $fin = Proration::calculate(Money::of(1000, 'USD'), Money::of(3000, 'USD'), $start, $end, $end);
    $despues = Proration::calculate(Money::of(1000, 'USD'), Money::of(3000, 'USD'), $start, $end, $end->addDay());

    expect($inicio->net->amountMinor)->toBe(2000)
        ->and($fin->net->amountMinor)->toBe(0)
        ->and($despues->net->amountMinor)->toBe(0);
});

test('el prorrateo en una moneda sin decimales sigue siendo entero', function () {
    $p = Proration::calculate(Money::of(1500, 'JPY'), Money::of(4000, 'JPY'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-01-11'));

    expect($p->net->amountMinor)->toBe($p->charge->amountMinor - $p->credit->amountMinor)
        ->and($p->net->currency)->toBe('JPY');
});

test('el fin de periodo mensual no deriva: un ancla del 31 vuelve al 31 tras febrero', function () {
    $anchor = CarbonImmutable::parse('2026-01-31 10:00:00');

    $feb = PeriodCalculator::end($anchor, $anchor, BillingInterval::Month);
    $mar = PeriodCalculator::end($feb, $anchor, BillingInterval::Month);

    expect($feb->toDateTimeString())->toBe('2026-02-28 10:00:00')
        ->and($mar->toDateTimeString())->toBe('2026-03-31 10:00:00');
});

test('el periodo anual cae el mismo día y respeta el 29 de febrero', function () {
    $anchor = CarbonImmutable::parse('2027-03-15 08:30:00');
    expect(PeriodCalculator::end($anchor, $anchor, BillingInterval::Year)->toDateTimeString())->toBe('2028-03-15 08:30:00');

    $leap = CarbonImmutable::parse('2028-02-29 00:00:00');
    expect(PeriodCalculator::end($leap, $leap, BillingInterval::Year)->toDateTimeString())->toBe('2029-02-28 00:00:00');
});
