<?php

// Los escenarios k6 (load/) no se ejecutan en la suite (necesitan una API en marcha); aquí se vigila que
// sigan completos y coherentes con el Makefile y la documentación (docs/load-testing.md).

$escenarios = ['smoke', 'load', 'stress', 'spike', 'soak'];

test('cada escenario k6 existe, define umbrales y exporta el resumen', function (string $nombre) {
    $ruta = base_path("load/{$nombre}.js");

    expect(is_file($ruta))->toBeTrue();
    $js = file_get_contents($ruta);
    expect($js)->toContain('thresholds(')
        ->toContain('summaryJson(')
        ->toContain('export const options');
})->with($escenarios);

test('cada escenario k6 tiene su target make load-* y está documentado', function (string $nombre) {
    $destino = $nombre === 'load' ? 'load-test' : "load-{$nombre}";

    expect(file_get_contents(base_path('make/load.mk')))->toContain("{$destino}:")
        ->and(file_get_contents(base_path('Makefile')))->toContain('include make/load.mk # module:load-testing')
        ->and(file_get_contents(base_path('docs/load-testing.md')))->toContain("load/{$nombre}.js");
})->with($escenarios);

test('la imagen de k6 va con versión fija, nunca latest', function () {
    $mk = file_get_contents(base_path('make/load.mk'));
    $sh = file_get_contents(base_path('load/run.sh'));

    expect($mk)->toMatch('/K6_IMAGE \?= grafana\/k6:\d+\.\d+\.\d+/')
        ->and($sh)->toMatch('/grafana\/k6:\d+\.\d+\.\d+/')
        ->and($mk.$sh)->not->toContain('k6:latest');
});

test('los umbrales base cubren errores, checks y p95 de lecturas, escrituras y login', function () {
    $config = file_get_contents(base_path('load/lib/config.js'));

    expect($config)->toContain('http_req_failed')
        ->toContain('checks')
        ->toContain('kind:read')
        ->toContain('kind:write')
        ->toContain('endpoint:login');
});

test('los límites del rate limiting se pueden subir por entorno con los mismos valores por defecto', function () {
    expect(config('rate-limiting.limits.auth.max_attempts'))->toBe(5)
        ->and(config('rate-limiting.limits.read.max_attempts'))->toBe(60)
        ->and(config('rate-limiting.limits.write.max_attempts'))->toBe(30)
        ->and(config('rate-limiting.limits.admin.max_attempts'))->toBe(10);
});

test('el stack temporal de carga limita la memoria a 2 GB en total', function () {
    $yml = file_get_contents(base_path('load/compose.yml'));
    preg_match_all('/mem_limit:\s*(\d+)m/', $yml, $m);

    expect($m[1])->toHaveCount(3)
        ->and(array_sum(array_map('intval', $m[1])))->toBeLessThanOrEqual(2048);
});
