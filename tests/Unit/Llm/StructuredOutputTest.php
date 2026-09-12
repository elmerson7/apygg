<?php

use App\Services\LLM\StructuredOutput;

test('extrae JSON plano', function () {
    expect(StructuredOutput::extract('{"ok":true,"n":2}'))->toBe(['ok' => true, 'n' => 2]);
});

test('extrae JSON dentro de un bloque markdown', function () {
    $content = "```json\n{\"ok\": true}\n```";

    expect(StructuredOutput::extract($content))->toBe(['ok' => true]);
});

test('extrae JSON con texto antes y después', function () {
    $content = 'Claro, aquí tienes el resultado: {"total": 3} Espero que te sirva.';

    expect(StructuredOutput::extract($content))->toBe(['total' => 3]);
});

test('extrae un array raíz', function () {
    expect(StructuredOutput::extract('[{"a":1},{"a":2}]'))->toBe([['a' => 1], ['a' => 2]]);
});

test('extrae objetos anidados sin cortarlos', function () {
    $content = 'Resultado: {"a": {"b": {"c": 1}}, "d": [1,2]} fin';

    expect(StructuredOutput::extract($content))->toBe(['a' => ['b' => ['c' => 1]], 'd' => [1, 2]]);
});

test('devuelve null si no hay JSON', function () {
    expect(StructuredOutput::extract('No he podido analizarlo.'))->toBeNull();
});

test('devuelve null con JSON malformado', function () {
    expect(StructuredOutput::extract('{"a": }'))->toBeNull();
});

test('devuelve null con contenido vacío', function () {
    expect(StructuredOutput::extract(''))->toBeNull()
        ->and(StructuredOutput::extract('   '))->toBeNull();
});

test('un escalar JSON no cuenta como salida estructurada', function () {
    expect(StructuredOutput::extract('42'))->toBeNull()
        ->and(StructuredOutput::extract('"texto"'))->toBeNull();
});
