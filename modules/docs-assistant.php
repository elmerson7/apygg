<?php

// Manifiesto del módulo «docs-assistant» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Asistente de documentación (RAG sobre docs/ con búsqueda de texto en PostgreSQL).',
    'core' => false,
    'depends' => [
        'llm',
    ],
    'files' => [
        'app/Services/Docs',
        'app/Contracts/DocsIndexerInterface.php',
        'app/Contracts/DocsServiceInterface.php',
        'app/Http/Controllers/Docs',
        'app/Http/Requests/Docs',
        'app/Models/Docs',
        'app/Console/Commands/DocsIndexCommand.php',
        'routes/api/docs.php',
        'config/docs-assistant.php',
        'tests/Feature/Modulos/DocsTest.php',
        'tests/Unit/Docs',
        'docs/docs-assistant.md',
    ],
    'migrations' => [
        'database/migrations/2026_01_01_000270_create_docs_tables.php',
    ],
    'tables' => [
        'doc_chunks',
        'doc_conversations',
    ],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
