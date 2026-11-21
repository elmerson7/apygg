<?php

// Manifiesto del módulo de núcleo «idempotency» (docs/modules.md): no se quita sin rehacer el boilerplate.
return [
    'description' => 'Idempotencia de peticiones.',
    'core' => true,
    'depends' => [],
    'files' => [
        'app/Console/Commands/IdempotencyCleanCommand.php',
        'app/Contracts/IdempotencyInterface.php',
        'app/Exceptions/IdempotencyException.php',
        'app/Http/Middleware/EnsureIdempotency.php',
        'app/Services/IdempotencyService.php',
        'app/Support/Idempotency',
        'config/idempotency.php',
        'database/migrations/2026_01_01_000300_create_idempotency_keys_table.php',
        'docs/idempotency.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
