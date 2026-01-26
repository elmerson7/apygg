<?php

namespace App\Console\Commands;

use App\Services\LogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Verificar salud de servicios del sistema';

    public function handle(): int
    {
        $this->info('Verificando salud de servicios...');

        try {
            $checks = [
                'database' => false,
                'redis' => false,
            ];

            // Verificar base de datos
            try {
                DB::connection()->getPdo();
                $checks['database'] = true;
                $this->info('✓ Base de datos: OK');
            } catch (\Exception $e) {
                $this->error('✗ Base de datos: ERROR - '.$e->getMessage());
            }

            // Verificar Redis
            try {
                Redis::ping();
                $checks['redis'] = true;
                $this->info('✓ Redis: OK');
            } catch (\Exception $e) {
                $this->error('✗ Redis: ERROR - '.$e->getMessage());
            }

            // Verificar Meilisearch (opcional)
            if (config('scout.driver') === 'meilisearch') {
                try {
                    $client = app(\Meilisearch\Client::class);
                    $client->health();
                    $this->info('✓ Meilisearch: OK');
                } catch (\Exception $e) {
                    $this->warn('✗ Meilisearch: ERROR - '.$e->getMessage());
                }
            }

            $allHealthy = ! in_array(false, $checks);

            if ($allHealthy) {
                $this->info('Todos los servicios están saludables.');
                LogService::info('Health check completado - Todos los servicios OK');

                return Command::SUCCESS;
            } else {
                $this->error('Algunos servicios tienen problemas.');
                LogService::warning('Health check completado - Algunos servicios con problemas', [
                    'checks' => $checks,
                ]);

                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Error al verificar salud: '.$e->getMessage());
            LogService::error('Error en health check', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
