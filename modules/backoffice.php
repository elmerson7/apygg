<?php

// Manifiesto del módulo «backoffice» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Panel interno de la plataforma (Filament 5) con MFA, para el dueño del SaaS.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Filament',
        'app/Providers/Filament',
        'config/backoffice.php',
        'resources/views/filament',
        'tests/Feature/Backoffice',
        'docs/backoffice.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [
        'backoffice.access',
    ],
    'env' => [],
    'packages' => [],
];
