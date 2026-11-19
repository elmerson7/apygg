# Dinero

> **Norma para módulos futuros (fase de suscripciones).** Hoy el repo no tiene módulo de cobros,
> precios ni facturas: `grep` no encuentra columnas `amount`, `price` ni `currency` en
> `database/migrations/`. Esta convención se fija **antes** para que el primer módulo que
> maneje dinero nazca bien, porque corregirla después implica migrar datos.

## Reglas

1. **Nunca `float` ni `double` ni `decimal` en PHP para dinero.** `0.1 + 0.2 !== 0.3`.
2. **Entero en la unidad menor** de la moneda (céntimos): `1999` = 19,99 EUR. Columna
   `unsignedBigInteger` (o `bigInteger` si puede ser negativo, p. ej. un reembolso).
3. **Siempre junto a su moneda ISO 4217** en mayúsculas: `currency` `char(3)`
   (`EUR`, `USD`, `PEN`). Un importe sin moneda no se guarda.
4. **Nombres:** `amount_minor` (o `<concepto>_minor`: `tax_minor`, `total_minor`) + `currency`.
   Si el módulo es pequeño, `amount` está permitido **solo si** el doc del módulo dice que son
   céntimos. Por defecto, usa `_minor`: no deja dudas.
5. **El exponente depende de la moneda** (EUR/USD 2, JPY 0, KWD 3). Guárdalo en una tabla/config
   de monedas, no lo supongas a 100.
6. **No se suman ni comparan importes de monedas distintas.** La conversión es una operación
   explícita con tasa y fecha guardadas.

## Esquema

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('subtotal_minor');
    $table->unsignedBigInteger('tax_minor');
    $table->unsignedBigInteger('total_minor');
    $table->char('currency', 3);
    $table->timestamps();
});
```

(Ejemplo de la convención, no existe en el repo.) Y, por la regla de `docs/migrations.md`,
restricciones en BD: `CHECK (total_minor >= 0)`, `CHECK (currency ~ '^[A-Z]{3}$')`.

## Cálculo y redondeo

- Opera siempre en enteros. Los porcentajes (impuestos, descuentos) se calculan con
  `intdiv`/aritmética entera o `bcmath` y se **redondean una sola vez, al final**, con una regla
  única y documentada (por defecto: mitad hacia arriba, `PHP_ROUND_HALF_UP`).
- Repartir un importe entre N partes: da el resto de céntimos a la primera parte (o la última);
  la suma de las partes debe ser **exactamente** el total. Pruébalo.
- Guarda el **resultado** del cálculo (subtotal, impuesto, total) en la factura; no lo recalcules
  al mostrarlo: una regla fiscal que cambie no debe alterar facturas emitidas.
- Una clase de valor (`Money`: `amountMinor`, `currency`) con `add`, `subtract`, `allocate` evita
  repetir la lógica y rechaza sumar monedas distintas. Va en `app/` sin estado estático.

## Entrada, salida y presentación

- **La API intercambia enteros en unidades menores + `currency`**, nunca cadenas formateadas:
  `{"total_minor": 1999, "currency": "EUR"}`. Los números JSON no pierden precisión hasta 2^53,
  de sobra.
- Un `JsonResource` puede añadir un campo de solo lectura ya formateado, pero **el cliente decide**
  la presentación.
- Presentación por locale en el borde (front o `NumberFormatter` de `intl`):
  `(new NumberFormatter('es_ES', NumberFormatter::CURRENCY))->formatCurrency($minor / 100, 'EUR')`;
  la división por 100 se hace **solo** para mostrar. El locale sale de `docs/internationalization.md`.
- Validación en el Form Request: `'total_minor' => ['required', 'integer', 'min:0']`,
  `'currency' => ['required', 'string', 'size:3', Rule::in(config('money.currencies'))]`
  (un `config/money.php` con las monedas admitidas y su exponente).

## Con proveedores de pago

Stripe y similares ya usan unidades menores enteras: se pasa el mismo valor sin convertir. Para
JPY y otras de 0 decimales no multipliques por 100. Más en `docs/integrations.md` (idempotencia).

## Pruebas obligatorias en un módulo de dinero

- Suma de partes = total en un reparto con resto.
- Redondeo en el límite (`x.5`).
- Moneda distinta → excepción de dominio (`BusinessLogicException`), no una suma silenciosa.
- Ida y vuelta API: `total_minor` entra y sale como `int` (cuidado: PostgreSQL devuelve `bigint`
  como string por PDO; castea a `integer` en el modelo, ver `docs/testing.md`).
