<?php

namespace App\Support\SeedLoad;

use Carbon\CarbonImmutable;

/**
 * Parámetros de una carga masiva de datos de prueba (`php artisan seed:load`). Inmutable y sin estado.
 */
final readonly class LoadOptions
{
    public function __construct(
        public int $tenants = 500,
        public int $usersPerTenant = 20,
        public int $filesPerTenant = 10,
        public int $notificationsPerUser = 2,
        public int $seed = 1,
        public int $batchSize = 100,
        public ?CarbonImmutable $asOf = null,
        public string $password = 'password',
        public int $credentialsLimit = 200,
    ) {}
}
