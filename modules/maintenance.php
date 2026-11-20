<?php

// Manifiesto del módulo «maintenance» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Modo mantenimiento de la API (503) con IPs y roles permitidos.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Console/Commands/MaintenanceDisableCommand.php',
        'app/Console/Commands/MaintenanceEnableCommand.php',
        'app/Contracts/MaintenanceServiceInterface.php',
        'app/Filament/Pages/Maintenance.php',
        'resources/views/filament/pages/maintenance.blade.php',
        'app/Http/Controllers/Admin/MaintenanceController.php',
        'app/Http/Middleware/CheckMaintenanceMode.php',
        'app/Http/Requests/Admin/EnableMaintenanceRequest.php',
        'app/Services/MaintenanceService.php',
        'config/maintenance.php',
        'tests/Feature/Maintenance',
        'docs/maintenance-mode.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [
        'maintenance.manage',
    ],
    'env' => [],
    'packages' => [],
];
