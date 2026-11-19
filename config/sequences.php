<?php

/*
|--------------------------------------------------------------------------
| Numeraciones atómicas (docs/sequences.md)
|--------------------------------------------------------------------------
|
| Definición por nombre de secuencia. Solo se lee al CREAR la secuencia (la primera vez que se pide);
| después manda la fila de la tabla `sequences`. Claves:
|
| - prefix:  texto delante del número; admite `{year}` (año del periodo). Ej.: 'F-{year}-'.
| - padding: ceros a la izquierda (6 → 000042). 0 = sin relleno.
| - start:   primer valor (por defecto 1).
| - max:     último valor permitido; pasado, `next()` lanza SequenceExhausted. null = sin tope.
| - reset:   'none' (por defecto) o 'yearly' (una numeración nueva cada año natural).
|
*/
return [
    'definitions' => [
        // 'invoice' => ['prefix' => 'F-{year}-', 'padding' => 6, 'reset' => 'yearly'],
    ],

    // Valores para una secuencia sin definición.
    'defaults' => [
        'prefix' => null,
        'padding' => 0,
        'start' => 1,
        'max' => null,
        'reset' => 'none',
    ],
];
