<?php

// Manifiesto del módulo «llm» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Gateway de IA (LLM) con áreas, auditoría de consumo y circuit breaker.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Services/LLM',
        'app/Http/Controllers/Llm',
        'app/Http/Requests/Llm',
        'app/Http/Resources/Llm',
        'app/Models/Llm',
        'app/Models/Logs/LlmAuditLog.php',
        'app/Console/Commands/LlmListCommand.php',
        'app/Console/Commands/LlmPruneLogsCommand.php',
        'app/Console/Commands/LlmTestCommand.php',
        'routes/api/llm.php',
        'config/llm.php',
        'database/seeders/LlmAreaSeeder.php',
        'tests/Feature/Modulos/LlmTest.php',
        'tests/Unit/Llm',
        'docs/llm.md',
    ],
    'migrations' => [
        'database/migrations/2026_09_12_000002_create_llm_tables.php',
    ],
    'tables' => [
        'llm_areas',
        'logs_llm',
    ],
    'permissions' => [
        'llm.manage',
        'llm.logs',
    ],
    'env' => [],
    'packages' => [],
];
