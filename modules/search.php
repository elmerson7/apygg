<?php

// Manifiesto del módulo «search» (docs/modules.md): qué le pertenece y de qué depende.
return [
    'description' => 'Búsqueda global con Scout y Meilisearch (o el driver database).',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Traits/Searchable.php',
        'app/Http/Controllers/Search',
        'app/Http/Requests/Search',
        'app/Http/Resources/Search',
        'app/Console/Commands/SyncSearchIndexesCommand.php',
        'routes/api/search.php',
        'config/scout.php',
        'docker/meilisearch',
        'tests/Feature/Modulos/SearchTest.php',
        'tests/Feature/Authorization/SearchAccessTest.php',
        'tests/Feature/Hallazgos/BusquedaYReglasPorTenantTest.php',
        'docs/meilisearch-configuration.md',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [
        'laravel/scout',
        'meilisearch/meilisearch-php',
    ],
];
