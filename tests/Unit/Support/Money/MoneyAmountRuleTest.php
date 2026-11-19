<?php

use App\Rules\MoneyAmount;
use Illuminate\Support\Facades\Validator;

function moneyValidates(array $data, mixed $rule): bool
{
    return ! Validator::make($data, ['amount' => ['required', $rule]])->fails();
}

test('acepta importes con hasta los decimales de su moneda', function () {
    expect(moneyValidates(['amount' => '12.34', 'currency' => 'PEN'], new MoneyAmount))->toBeTrue()
        ->and(moneyValidates(['amount' => '12', 'currency' => 'PEN'], new MoneyAmount))->toBeTrue()
        ->and(moneyValidates(['amount' => '1500', 'currency' => 'JPY'], new MoneyAmount))->toBeTrue()
        ->and(moneyValidates(['amount' => '1.234', 'currency' => 'KWD'], new MoneyAmount))->toBeTrue();
});

test('rechaza decimales de más, formatos raros y floats', function () {
    expect(moneyValidates(['amount' => '12.345', 'currency' => 'PEN'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => '12.5', 'currency' => 'JPY'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => '1,234.50', 'currency' => 'PEN'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => 12.34, 'currency' => 'PEN'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => '99999999999999999999', 'currency' => 'PEN'], new MoneyAmount))->toBeFalse();
});

test('rechaza moneda ausente o desconocida y negativos salvo que se permitan', function () {
    expect(moneyValidates(['amount' => '1'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => '1', 'currency' => 'XXX'], new MoneyAmount))->toBeFalse()
        ->and(moneyValidates(['amount' => '-1'], MoneyAmount::for('PEN')))->toBeFalse()
        ->and(moneyValidates(['amount' => '-1'], MoneyAmount::for('PEN')->allowNegative()))->toBeTrue()
        ->and(moneyValidates(['amount' => '5', 'moneda' => 'EUR'], MoneyAmount::currencyField('moneda')))->toBeTrue();
});
