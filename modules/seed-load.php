<?php

// Manifiesto del módulo «seed-load» (docs/modules.md): datos masivos reproducibles para desarrollo y carga.
return [
    'description' => 'Datos masivos reproducibles (make seed-load) para desarrollo, staging y pruebas de carga.',
    'core' => false,
    'depends' => ['billing'],
    'files' => [
        'app/Support/SeedLoad',
        'app/Console/Commands/SeedLoadCommand.php',
        'app/Console/Commands/SeedLoadPurgeCommand.php',
        'tests/Feature/SeedLoad',
        'docs/seed-load.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
