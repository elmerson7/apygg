<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use Illuminate\Console\Command;

/**
 * DatabaseSchemaDumpCommand
 *
 * Exporta solo la estructura (DDL) de la base de datos actual a un archivo SQL,
 * sin datos. Útil para versionar el esquema, comparar entornos o entregar el
 * modelo de datos sin exponer información.
 *
 * Usa `pg_dump --schema-only` con `--no-owner --no-acl` para que el DDL sea
 * portable entre entornos.
 *
 * Uso:
 *   php artisan db:schema-dump
 *   php artisan db:schema-dump --output=storage/app/schema.sql
 */
class DatabaseSchemaDumpCommand extends Command
{
    use ReadsTypedInput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:schema-dump
                            {--output= : Ruta del archivo SQL (por defecto: database/schema.sql)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exportar la estructura (CREATE TABLE, etc.) de la BD a un archivo SQL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (($connection['driver'] ?? null) !== 'pgsql') {
            $this->error('Este comando solo soporta PostgreSQL. Driver actual: '.($connection['driver'] ?? 'desconocido'));

            return Command::FAILURE;
        }

        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '5432';

        $outputPath = $this->stringOption('output') ?: database_path('schema.sql');
        $outputDir = dirname($outputPath);

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            $this->error("No se pudo crear el directorio: {$outputDir}");

            return Command::FAILURE;
        }

        $this->info("Exportando esquema de la base de datos '{$database}'...");

        // pg_dump: solo esquema, formato texto, sin OWNER ni ACL para DDL portable
        $command = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s --schema-only -F p --no-owner --no-acl -f %s',
            escapeshellarg((string) $password),
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database),
            escapeshellarg($outputPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command.' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('Error al exportar el esquema: '.implode("\n", $output));

            return Command::FAILURE;
        }

        $size = file_exists($outputPath) ? filesize($outputPath) : 0;

        $this->info("✓ Esquema exportado correctamente a: {$outputPath}");
        $this->line('  Tamaño: '.number_format($size / 1024, 1).' KB');

        return Command::SUCCESS;
    }
}
