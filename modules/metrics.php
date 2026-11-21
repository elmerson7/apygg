<?php

// Manifiesto del módulo «metrics» (docs/modules.md): métricas Prometheus en GET /metrics.
return [
    'description' => 'Métricas Prometheus (GET /metrics): peticiones, latencia, colas, jobs fallidos y BD.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Contracts/MetricsServiceInterface.php',
        'app/Http/Controllers/Metrics',
        'app/Http/Middleware/AuthorizeMetrics.php',
        'app/Http/Middleware/RecordMetrics.php',
        'app/Services/MetricsService.php',
        'config/metrics.php',
        'routes/api/metrics.php',
        'tests/Feature/Metrics',
        'docs/metrics.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [
        'METRICS_ENABLED',
        'METRICS_TOKEN',
        'METRICS_ALLOWED_IPS',
        'METRICS_QUEUES',
    ],
    'packages' => [],
];
