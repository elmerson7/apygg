<?php

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class MoneyCastInvoice extends Model
{
    protected $table = 'money_cast_invoices';

    protected $guarded = [];

    public $timestamps = false;

    protected $appends = ['total'];

    protected function casts(): array
    {
        return ['total' => MoneyCast::class, 'tax' => MoneyCast::class.':tax_currency'];
    }
}

beforeEach(function () {
    Schema::create('money_cast_invoices', function (Blueprint $table) {
        $table->id();
        $table->bigInteger('total_minor')->nullable();
        $table->char('currency', 3)->nullable();
        $table->bigInteger('tax_minor')->nullable();
        $table->char('tax_currency', 3)->nullable();
    });
});

test('guarda en dos columnas y recupera el mismo Money (ida y vuelta)', function () {
    $invoice = MoneyCastInvoice::create(['total' => Money::of(123_456_789_012, 'PEN'), 'tax' => Money::of(-5, 'JPY')]);

    $fresh = MoneyCastInvoice::findOrFail($invoice->id);

    expect($fresh->getRawOriginal('total_minor'))->not->toBeNull()
        ->and($fresh->total)->toBeInstanceOf(Money::class)
        ->and($fresh->total->amountMinor)->toBe(123_456_789_012)
        ->and($fresh->total->currency)->toBe('PEN')
        ->and($fresh->tax->equals(Money::of(-5, 'JPY')))->toBeTrue();

    $row = DB::table('money_cast_invoices')->first();
    expect((int) $row->total_minor)->toBe(123_456_789_012)->and($row->currency)->toBe('PEN')->and($row->tax_currency)->toBe('JPY');
});

test('un valor nulo deja ambas columnas en null y se serializa a JSON como entero', function () {
    $invoice = MoneyCastInvoice::create(['total' => null]);

    expect(MoneyCastInvoice::find($invoice->id)->total)->toBeNull();

    $invoice->total = Money::of(1999, 'EUR');
    $invoice->save();

    expect(json_decode($invoice->fresh()->toJson(), true)['total'])->toBe(['amount_minor' => 1999, 'currency' => 'EUR']);
});

test('rechaza asignar algo que no sea Money', function () {
    expect(fn () => MoneyCastInvoice::create(['total' => '12.34']))->toThrow(InvalidArgumentException::class);
});
