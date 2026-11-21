<?php

// Manifiesto del módulo «load-testing» (docs/modules.md): escenarios de carga k6 y su stack temporal.
return [
    'description' => 'Escenarios de carga k6 (humo, carga, estrés, pico, resistencia) y stack temporal de humo (make load-*).',
    'core' => false,
    'depends' => ['seed-load'],
    'files' => [
        'load',
        'make/load.mk',
        'tests/Unit/Load',
        'docs/load-testing.md',
        'docs/performance-baseline.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
