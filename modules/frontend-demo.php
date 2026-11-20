<?php

// Manifiesto del módulo «frontend-demo» (docs/modules.md): cliente de pruebas con tipos generados + Playwright.
// Sin dependencias del núcleo PHP: se quita borrando la carpeta frontend-demo/ y estos archivos.
return [
    'description' => 'Cliente web de pruebas (Vite + React + TypeScript, tipos de docs/openapi.json) y recorridos Playwright.',
    'core' => false,
    'depends' => [],
    'files' => [
        'frontend-demo',
        'make/frontend.mk',
        'scripts/frontend-e2e.sh',
        'docs/frontend-demo.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
