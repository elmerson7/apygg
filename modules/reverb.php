<?php

// Manifiesto del módulo «reverb» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'WebSockets con Laravel Reverb: autenticación de canales y canales de ejemplo.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Http/Controllers/Auth/BroadcastAuthController.php',
        'routes/channels.php',
        'config/reverb.php',
        'tests/Feature/Hallazgos/CanalesPresenciaTest.php',
        'docs/websockets.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [
        'laravel/reverb',
    ],
];
