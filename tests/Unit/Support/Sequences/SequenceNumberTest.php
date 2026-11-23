<?php

use App\Support\Sequences\SequenceNumber;

test('sin prefijo ni relleno devuelve el entero', function () {
    expect(SequenceNumber::format(42, null, 0))->toBe(42);
});

test('rellena con ceros y antepone el prefijo', function () {
    expect(SequenceNumber::format(42, 'F-', 6))->toBe('F-000042')
        ->and(SequenceNumber::format(42, null, 6))->toBe('000042')
        ->and(SequenceNumber::format(42, 'T', 0))->toBe('T42')
        ->and(SequenceNumber::format(1234567, 'F-', 3))->toBe('F-1234567');
});

test('sustituye {year} por el periodo', function () {
    expect(SequenceNumber::format(7, 'INV-{year}-', 4, '2026'))->toBe('INV-2026-0007')
        ->and(SequenceNumber::format(7, 'INV-{year}-', 4, '', '2030'))->toBe('INV-2030-0007');
});

test('sin periodo explícito {year} usa el año actual', function () {
    expect(SequenceNumber::format(7, 'INV-{year}-', 4))->toBe('INV-'.date('Y').'-0007');
});

test('un relleno de 1 sin prefijo ya devuelve texto y uno de 0 o negativo devuelve el entero', function () {
    expect(SequenceNumber::format(5, null, 1))->toBe('5')
        ->and(SequenceNumber::format(5, '', 1))->toBe('5')
        ->and(SequenceNumber::format(5, '', 0))->toBe(5)
        ->and(SequenceNumber::format(5, '', -3))->toBe(5)
        ->and(SequenceNumber::format(5, 'A', -3))->toBe('A5');
});

test('el periodo manda sobre el año de respaldo y un relleno exacto no añade ceros', function () {
    expect(SequenceNumber::format(12, 'X{year}-', 2, '2025', '2030'))->toBe('X2025-12')
        ->and(SequenceNumber::format(12, 'X-', 3))->toBe('X-012');
});
