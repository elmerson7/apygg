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
