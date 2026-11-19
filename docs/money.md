# Dinero

> **Implementado.** `App\Support\Money\Money`, `config/currencies.php`, `App\Casts\MoneyCast` y la regla
> `App\Rules\MoneyAmount`. El repo base no tiene aún un módulo de cobros: estas piezas son la base para
> que el primero (facturas, pedidos, suscripciones) nazca bien, porque corregir la convención después
> implica migrar datos.

## Reglas

1. **Nunca `float` ni `double` ni `decimal` en PHP para dinero.** `0.1 + 0.2 !== 0.3`.
2. **Entero en la unidad menor** de la moneda (céntimos): `1999` = 19,99 EUR. Columna `bigInteger`
   (con signo: un reembolso es negativo; añade `CHECK (x >= 0)` si no puede serlo).
3. **Siempre junto a su moneda ISO 4217** en mayúsculas: `currency` `char(3)`. Un importe sin moneda no se guarda.
4. **Nombres:** `<concepto>_minor` (`subtotal_minor`, `tax_minor`, `total_minor`) + `currency`.
5. **El exponente depende de la moneda** (EUR/USD 2, JPY/CLP 0, KWD 3) y vive en `config/currencies.php`.
   Una moneda que no esté ahí se rechaza (`UnsupportedCurrency`): añádela con su exponente.
6. **No se suman ni comparan importes de monedas distintas** (`CurrencyMismatch`, un `BusinessLogicException`
   422). La conversión es una operación explícita con tasa y fecha guardadas.
7. **Una migración nueva no puede declarar `float`/`double`/`decimal` en columnas cuyo nombre contenga
   `amount`, `price`, `total` o `balance`**: lo comprueba `tests/Arch/ArchTest.php`.

## `Money`

Valor inmutable (`final readonly`), sin estado estático (seguro con Octane).

```php
use App\Support\Money\{Money, Rounding};

$price = Money::fromDecimalString('12.34', 'PEN');   // 1234 minor
$price->amountMinor;                                 // 1234 (int)
$price->toDecimalString();                           // "12.34"; JPY: "1500"
Money::of(1999, 'EUR');                              // desde unidades menores

$total = $price->add(Money::of(66, 'PEN'));         // 13,00 PEN
$total->subtract($price);
$total->negate(); $total->abs();

// Redondeo SIEMPRE explícito y una sola vez, al final:
$igv   = $price->percentage('18', Rounding::HalfUp);        // 18 % con mitad hacia fuera de cero
$disc  = $price->multiply('0.85', Rounding::HalfEven);      // factor como cadena, nunca float
$exact = Money::fromDecimalString('12.345', 'PEN', Rounding::HalfUp); // sin Rounding, más decimales = InvalidMoney

// Repartir sin perder unidades: la suma de las partes es EXACTAMENTE el total.
Money::of(100, 'EUR')->allocate([1, 1, 1]);   // 34 + 33 + 33
Money::of(100, 'EUR')->split(3);              // igual
Money::of(100, 'EUR')->allocate([70, 30]);    // 70 + 30

$a->compare($b); $a->equals($b); $a->greaterThan($b); $a->isZero(); $a->isNegative();

$price->format('es_PE');   // "S/ 12,34" (intl NumberFormatter, decimales de SU moneda)
json_encode($price);       // {"amount_minor":1234,"currency":"PEN"}
```

`Rounding`: `HalfUp` (mitad hacia fuera de cero; la habitual), `HalfEven` (del banquero), `Down` (hacia cero),
`Up` (hacia fuera de cero). Los cálculos intermedios usan bcmath (12 decimales), así que no hay desbordes
ni redondeos dobles. Un resultado fuera de 64 bits lanza `InvalidMoney`.

`allocate()`: el resto se da de a **una unidad menor a las primeras partes** (con proporción > 0); un
importe negativo se reparte en valor absoluto y se niegan las partes.

`format()` es **solo presentación**: `NumberFormatter` no admite cadenas, así que usa un `float` (exacto
hasta 2^53 unidades menores; JPY y similares se pasan como `int`). Nunca calcules con su resultado.

## Persistencia: `MoneyCast`

Un atributo `Money` en **dos columnas** (`<atributo>_minor` + `currency`):

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('subtotal_minor');
    $table->bigInteger('tax_minor');
    $table->bigInteger('total_minor');
    $table->char('currency', 3);
    $table->timestamps();
});
DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_currency_format CHECK (currency ~ '^[A-Z]{3}\$')");
DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_nonneg CHECK (total_minor >= 0)');

class Invoice extends Model
{
    protected $appends = ['total'];   // opcional: sale en el JSON (`toJson()`) como {amount_minor, currency}

    protected function casts(): array
    {
        return [
            'subtotal' => MoneyCast::class,                 // subtotal_minor + currency
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'fee' => MoneyCast::class.':fee_currency',      // fee_minor + fee_currency (otra moneda)
        ];
    }
}

$invoice->total = Money::of(1999, 'EUR');   // escribe total_minor y currency
$invoice->total->amountMinor;               // int (PDO devuelve bigint como string: el cast lo convierte)
```

Si todos los importes de una fila comparten `currency`, asigna siempre importes de esa moneda: el cast
escribe la columna `currency` con la del último que asignes. Asignar algo que no sea `Money` (ni `null`)
lanza `InvalidArgumentException`. Con `*_minor` o `currency` nulos, el atributo es `null`.

## Validación: `MoneyAmount`

La API recibe el importe como **cadena decimal** (`"12.34"`) y la valida con los decimales de su moneda; los
floats JSON (`12.34` sin comillas) se rechazan para no heredar su imprecisión.

```php
'amount'   => ['required', new MoneyAmount],                    // moneda del campo `currency`
'currency' => ['required', 'string', 'size:3'],
'refund'   => ['required', MoneyAmount::for('PEN')->allowNegative()],
'cuota'    => ['required', MoneyAmount::currencyField('moneda')],
```

Después: `Money::fromDecimalString($request->validated('amount'), $request->validated('currency'))`.
Alternativa: aceptar `*_minor` entero (`'integer'`) y `currency`, que es lo que devuelve la API.

## API y presentación

- **La API devuelve enteros en unidades menores + `currency`**, nunca cadenas formateadas:
  `{"total_minor": 1999, "currency": "EUR"}` (o `Money` serializado). El cliente decide la presentación.
- Guarda el **resultado** de los cálculos (subtotal, impuesto, total) en la factura; no lo recalcules al
  mostrarlo: una regla fiscal que cambie no debe alterar facturas emitidas.

## Con proveedores de pago

Stripe y similares ya usan unidades menores enteras: pasa `amountMinor` sin convertir (JPY: no multipliques
por 100). Más en `docs/integrations.md` (idempotencia).

## Pruebas obligatorias en un módulo de dinero

Ya cubiertas para `Money` en `tests/Unit/Support/Money/` (redondeo en el límite x.5, monedas de 0 y 3
decimales, `allocate` con resto y suma exacta, moneda distinta, ida y vuelta del cast). En tu módulo,
prueba además: que la suma de las partes de **tu** reparto es el total, y que `total_minor` entra y sale
como `int` por la API.
