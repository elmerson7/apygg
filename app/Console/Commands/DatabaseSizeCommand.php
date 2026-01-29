<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DatabaseSizeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:size {--table= : Mostrar tamaño de una tabla específica}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mostrar tamaño de la base de datos y tablas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = config('database.connections.pgsql.database');

        if ($this->option('table')) {
            return $this->showTableSize($this->option('table'));
        }

        return $this->showDatabaseSize($database);
    }

    /**
     * Mostrar tamaño de la base de datos completa
     */
    protected function showDatabaseSize(string $database): int
    {
        $this->info("📊 Tamaño de la base de datos: {$database}");
        $this->newLine();

        try {
            // Tamaño total de la base de datos (en bytes)
            $dbSizeBytes = DB::selectOne('SELECT pg_database_size(?) as size', [$database]);
            $this->line("💾 Tamaño total: <fg=cyan>{$this->formatSize($dbSizeBytes->size)}</>");
            $this->newLine();

            // Tamaño de todas las tablas (obtener en bytes para formatear mejor)
            $tables = DB::select("
                SELECT 
                    tablename,
                    pg_total_relation_size('public.'||tablename) AS total_size_bytes,
                    pg_relation_size('public.'||tablename) AS table_size_bytes,
                    (pg_total_relation_size('public.'||tablename) - pg_relation_size('public.'||tablename)) AS indexes_size_bytes
                FROM pg_tables
                WHERE schemaname = 'public'
                ORDER BY pg_total_relation_size('public.'||tablename) DESC
            ");

            if (empty($tables)) {
                $this->warn('No se encontraron tablas.');

                return Command::SUCCESS;
            }

            $this->info('📋 Tablas (ordenadas por tamaño):');
            $this->newLine();

            $headers = ['Tabla', 'Tabla', 'Índices', 'Tamaño Total'];
            $rows = [];

            foreach ($tables as $table) {
                $rows[] = [
                    $table->tablename,
                    $this->formatSize($table->table_size_bytes),
                    $this->formatSize($table->indexes_size_bytes),
                    $this->formatSize($table->total_size_bytes),
                ];
            }

            $this->table($headers, $rows);
            $this->newLine();

            // Mostrar tablas de logs con conteo de registros
            $this->showLogsTablesInfo();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error al obtener tamaño de la base de datos: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Mostrar tamaño de una tabla específica
     */
    protected function showTableSize(string $tableName): int
    {
        $this->info("📊 Información de la tabla: <fg=cyan>{$tableName}</>");
        $this->newLine();

        try {
            // Verificar que la tabla existe
            $tableExists = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = ?
                ) as exists
            ", [$tableName]);

            if (! $tableExists->exists) {
                $this->error("La tabla '{$tableName}' no existe.");

                return Command::FAILURE;
            }

            // Tamaño de la tabla (obtener en bytes para formatear mejor)
            $sizes = DB::selectOne("
                SELECT 
                    pg_total_relation_size('public.'||?) AS total_size_bytes,
                    pg_relation_size('public.'||?) AS table_size_bytes,
                    (pg_total_relation_size('public.'||?) - pg_relation_size('public.'||?)) AS indexes_size_bytes
            ", [$tableName, $tableName, $tableName, $tableName]);

            // Número de registros
            $rowCount = DB::table($tableName)->count();

            // Información de columnas
            $columns = DB::select("
                SELECT 
                    column_name,
                    data_type,
                    character_maximum_length
                FROM information_schema.columns
                WHERE table_schema = 'public' 
                AND table_name = ?
                ORDER BY ordinal_position
            ", [$tableName]);

            $this->line("💾 Tamaño total: <fg=cyan>{$this->formatSize($sizes->total_size_bytes)}</>");
            $this->line("📄 Tamaño de tabla: <fg=yellow>{$this->formatSize($sizes->table_size_bytes)}</>");
            $this->line("🔍 Tamaño de índices: <fg=yellow>{$this->formatSize($sizes->indexes_size_bytes)}</>");
            $this->line("📊 Registros: <fg=green>{$rowCount}</>");
            $this->newLine();

            // Mostrar columnas si son pocas (máximo 20)
            if (count($columns) <= 20) {
                $this->info('📋 Columnas:');
                $columnHeaders = ['Columna', 'Tipo', 'Longitud'];
                $columnRows = [];

                foreach ($columns as $column) {
                    $columnRows[] = [
                        $column->column_name,
                        $column->data_type,
                        $column->character_maximum_length ?: '-',
                    ];
                }

                $this->table($columnHeaders, $columnRows);
            } else {
                $columnCount = count($columns);
                $this->line("📋 Columnas: <fg=yellow>{$columnCount} columnas</>");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error al obtener información de la tabla: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Mostrar información específica de tablas de logs
     */
    protected function showLogsTablesInfo(): void
    {
        $logTables = ['logs_api', 'logs_security', 'logs_activity'];
        $existingLogTables = [];

        foreach ($logTables as $table) {
            $exists = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = ?
                ) as exists
            ", [$table]);

            if ($exists->exists) {
                $existingLogTables[] = $table;
            }
        }

        if (empty($existingLogTables)) {
            return;
        }

        $this->info('📝 Tablas de logs:');
        $this->newLine();

        $logHeaders = ['Tabla', 'Tamaño', 'Registros'];
        $logRows = [];

        foreach ($existingLogTables as $table) {
            try {
                $sizeBytes = DB::selectOne("
                    SELECT pg_total_relation_size('public.'||?) AS size_bytes
                ", [$table]);

                $count = DB::table($table)->count();

                $logRows[] = [
                    $table,
                    $this->formatSize($sizeBytes->size_bytes),
                    number_format($count, 0, '', '.'),
                ];
            } catch (\Exception $e) {
                // Ignorar errores en tablas específicas
                continue;
            }
        }

        if (! empty($logRows)) {
            $this->table($logHeaders, $logRows);
            $this->newLine();
        }
    }

    /**
     * Formatear tamaño en bytes a la unidad más apropiada (KB, MB, GB)
     * Con conversión automática y formato decimal cuando sea necesario
     *
     * @param  int|float  $bytes  Tamaño en bytes
     * @return string Tamaño formateado (ej: "5.2 MB", "128 KB", "1.5 GB")
     */
    protected function formatSize(int|float $bytes): string
    {
        if ($bytes == 0) {
            return '0 bytes';
        }

        $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float) $bytes;

        // Convertir a la unidad más apropiada
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        // Formatear con decimales apropiados
        if ($unitIndex == 0) {
            // Para bytes, mostrar sin decimales
            return number_format($size, 0, '.', '').' '.$units[$unitIndex];
        } elseif ($size >= 100) {
            // Para valores >= 100, mostrar sin decimales
            return number_format($size, 0, '.', '').' '.$units[$unitIndex];
        } elseif ($size >= 10) {
            // Para valores >= 10, mostrar 1 decimal
            return number_format($size, 1, '.', '').' '.$units[$unitIndex];
        } else {
            // Para valores < 10, mostrar 2 decimales
            return number_format($size, 2, '.', '').' '.$units[$unitIndex];
        }
    }
}
