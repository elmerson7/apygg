<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Support\SeedLoad\LoadDataGenerator;
use App\Support\SeedLoad\LoadOptions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Datos masivos y realistas para pruebas de volumen y de carga (docs/seed-load.md).
 *
 * Uso: php artisan seed:load --tenants=500 --users-per-tenant=20 --files-per-tenant=10 --seed=1
 * Inverso: php artisan seed:load:purge
 */
class SeedLoadCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'seed:load
        {--tenants=500 : Empresas a crear}
        {--users-per-tenant=20 : Usuarios (miembros) por empresa}
        {--files-per-tenant=10 : Archivos (solo metadatos) por empresa}
        {--notifications-per-user=2 : Avisos en la bandeja por usuario}
        {--seed=1 : Semilla: la misma semilla y los mismos tamaños dan los mismos datos}
        {--batch=100 : Empresas por lote (una transacción por lote)}
        {--as-of= : Fecha de referencia ISO 8601 (por defecto, ahora); fíjala para repetir datos idénticos}
        {--password=password : Contraseña de todos los usuarios generados}
        {--credentials= : CSV con credenciales de ejemplo (por defecto storage/app/seed-load/credentials.csv)}
        {--credentials-limit=200 : Empresas incluidas en el CSV de credenciales (0 = no escribirlo)}';

    protected $description = 'Genera datos masivos de prueba por lotes (empresas, usuarios, suscripciones, facturas, pagos, archivos); NO para producción';

    public function handle(LoadDataGenerator $generator): int
    {
        if (app()->environment('production')) {
            $this->error('seed:load no se ejecuta en producción.');

            return self::FAILURE;
        }

        $options = new LoadOptions(
            tenants: max(1, (int) $this->option('tenants')),
            usersPerTenant: max(1, (int) $this->option('users-per-tenant')),
            filesPerTenant: max(0, (int) $this->option('files-per-tenant')),
            notificationsPerUser: max(0, (int) $this->option('notifications-per-user')),
            seed: (int) $this->option('seed'),
            batchSize: max(1, (int) $this->option('batch')),
            asOf: $this->option('as-of') ? CarbonImmutable::parse(($this->stringOption('as-of') ?? '')) : null,
            password: ($this->stringOption('password') ?? ''),
            credentialsLimit: max(0, (int) $this->option('credentials-limit')),
        );

        if (! config('multi-tenancy.enabled')) {
            $this->warn('MULTI_TENANCY_ENABLED=false: los datos se crean, pero la API no los aísla por empresa hasta activarlo.');
        }

        // Prerrequisitos idempotentes: roles (con los de empresa) y catálogo de planes.
        $this->callSilently('db:seed', ['--class' => 'Database\\Seeders\\RoleSeeder', '--force' => true]);
        $this->callSilently('billing:sync-plans');

        $started = microtime(true);
        $bar = $this->output->createProgressBar($options->tenants);
        $bar->setFormat(' %current%/%max% empresas [%bar%] %percent:3s%%  %elapsed:6s%  mem %memory:6s%');
        $bar->start();

        $totals = $generator
            ->onProgress(fn (int $done) => $bar->setProgress($done))
            ->run($options);

        $bar->finish();
        $this->newLine(2);

        $seconds = max(0.001, microtime(true) - $started);

        $this->table(['Concepto', 'Filas'], [
            ['empresas', $totals['tenants']],
            ['usuarios', $totals['users']],
            ['membresías', $totals['memberships']],
            ['suscripciones', $totals['subscriptions']],
            ['facturas', $totals['invoices']],
            ['pagos (intentos)', $totals['payments']],
            ['archivos', $totals['files']],
            ['avisos en bandeja', $totals['notifications']],
        ]);
        $this->info(sprintf('Índices %d-%d, %.1f s (%.0f empresas/s), memoria pico PHP %.0f MB.', $totals['first_index'], $totals['last_index'], $seconds, $totals['tenants'] / $seconds, memory_get_peak_usage(true) / 1048576));

        if ($options->credentialsLimit > 0) {
            $path = $this->writeCredentials(($this->stringOption('credentials') ?: storage_path('app/seed-load/credentials.csv')), $totals['credentials'], $options->password, $options->credentialsLimit);
            $this->line("Credenciales de ejemplo: {$path} (contraseña común: \"{$options->password}\").");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{tenant_id: int, slug: string, email: string, role: string}>  $rows
     */
    private function writeCredentials(string $path, array $rows, string $password, int $limit): string
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->warn("No se pudo escribir {$path}.");

            return $path;
        }

        fputcsv($handle, ['tenant_id', 'tenant_slug', 'email', 'password', 'tenant_role'], ',', '"', '');

        foreach (array_slice($rows, 0, $limit * 2) as $row) {
            fputcsv($handle, [$row['tenant_id'], $row['slug'], $row['email'], $password, $row['role']], ',', '"', '');
        }

        fclose($handle);

        return $path;
    }
}
