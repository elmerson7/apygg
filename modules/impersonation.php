<?php

// Manifiesto del módulo «impersonation» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Impersonación de soporte con motivo obligatorio, TTL y auditoría.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Contracts/ImpersonationServiceInterface.php',
        'app/Exceptions/ImpersonationException.php',
        'app/Http/Controllers/Admin/ImpersonationController.php',
        'app/Http/Middleware/EnforceImpersonation.php',
        'app/Http/Requests/Admin/StartImpersonationRequest.php',
        'app/Services/ImpersonationService.php',
        'config/impersonation.php',
        'routes/api/impersonation.php',
        'tests/Feature/Impersonation',
        'docs/impersonation.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [
        'users.impersonate',
    ],
    'env' => [],
    'packages' => [],
];
