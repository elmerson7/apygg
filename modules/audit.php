<?php

// Manifiesto del módulo de núcleo «audit» (docs/modules.md): no se quita sin rehacer el boilerplate.
return [
    'description' => 'Auditoría de negocio.',
    'core' => true,
    'depends' => [],
    'files' => [
        'app/Console/Commands/AuditPruneCommand.php',
        'app/Contracts/AuditServiceInterface.php',
        'app/Contracts/SecurityAuditServiceInterface.php',
        'app/Exceptions/AuditException.php',
        'app/Http/Controllers/Audit',
        'app/Http/Controllers/Concerns/StreamsAuditCsv.php',
        'app/Http/Requests/Audit',
        'app/Models/AuditLog.php',
        'app/Services/AuditService.php',
        'app/Services/SecurityAuditService.php',
        'app/Traits/Auditable.php',
        'config/audit.php',
        'database/migrations/2026_01_01_000380_create_audit_logs_table.php',
        'docs/audit.md',
        'docs/security-audit.md',
        'routes/api/audit.php',
        'tests/Feature/Audit',
        'tests/Unit/Security/SecurityAuditServiceTest.php',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
