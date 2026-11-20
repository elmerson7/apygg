<?php

use App\Support\Modules\MarkerEditor;

/*
 * Marcadores de módulo (docs/modules.md §Marcadores): línea `// module:x` y bloque `module:x:start/end`.
 */

test('quita las líneas marcadas con el módulo y deja el resto intacto', function () {
    $in = "a;\nb; // module:billing\nc;\nd; # module:billing\n<x/> <!-- module:billing -->\ne;";

    $out = (new MarkerEditor)->strip($in, 'billing');

    expect($out['content'])->toBe("a;\nc;\ne;")
        ->and($out['removed'])->toBe(3);
});

test('quita los bloques delimitados con sus marcas', function () {
    $in = "a;\n// module:llm:start\nx;\ny;\n// module:llm:end\nb;";

    $out = (new MarkerEditor)->strip($in, 'llm');

    expect($out['content'])->toBe("a;\nb;")
        ->and($out['removed'])->toBe(4);
});

test('no toca los marcadores de otros módulos ni nombres que solo empiezan igual', function () {
    $in = "a; // module:llm-extra\nb; // module:llm\nc; // module:search";

    $out = (new MarkerEditor)->strip($in, 'llm');

    expect($out['content'])->toBe("a; // module:llm-extra\nc; // module:search");
});

test('un bloque sin cerrar o un cierre sin apertura falla en vez de borrar de más', function () {
    $editor = new MarkerEditor;

    expect(fn () => $editor->strip("a;\n// module:x:start\nb;", 'x'))->toThrow(RuntimeException::class)
        ->and(fn () => $editor->strip("a;\n// module:x:end\nb;", 'x'))->toThrow(RuntimeException::class);
});

test('es idempotente: sin marcadores devuelve el mismo texto', function () {
    $out = (new MarkerEditor)->strip("a;\nb;", 'x');

    expect($out)->toBe(['content' => "a;\nb;", 'removed' => 0]);
});
