<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;

class SyncSearchIndexesCommand extends Command
{
    protected $signature = 'search:sync-indexes';

    protected $description = 'Sincronizar índices de búsqueda con Meilisearch';

    public function handle(): int
    {
        $this->info('Sincronizando índices de búsqueda...');

        try {
            // Verificar si Scout está configurado
            $driver = config('scout.driver');
            if (! $driver) {
                $this->warn('Scout no está configurado. Saltando sincronización.');

                return Command::SUCCESS;
            }

            // Verificar si Meilisearch está disponible
            if ($driver === 'meilisearch') {
                try {
                    $host = config('scout.meilisearch.host');
                    $ch = curl_init($host.'/health');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                    curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode !== 200) {
                        throw new \Exception('Meilisearch not healthy');
                    }
                } catch (\Exception $e) {
                    $this->warn('Meilisearch no está disponible. Saltando sincronización.');

                    return Command::SUCCESS;
                }
            }

            $synced = 0;

            // Sincronizar usuarios si tienen el trait Searchable
            $traits = class_uses_recursive(User::class);
            $hasSearchable = isset($traits[Searchable::class]) || isset($traits[\App\Traits\Searchable::class]);

            if ($hasSearchable) {
                $this->info('Sincronizando usuarios...');
                // Iterar sobre los modelos y sincronizarlos individualmente
                // Usar chunk para evitar problemas de memoria con muchos registros
                User::chunk(100, function ($users) use (&$synced) {
                    foreach ($users as $user) {
                        // El método searchable() viene del trait Searchable de Laravel Scout
                        $user->searchable();
                        $synced++;
                    }
                });
            }

            // Aquí puedes agregar más modelos que necesiten sincronización
            // Ejemplo:
            // if (method_exists(Post::class, 'searchable')) {
            //     Post::all()->searchable();
            // }

            $this->info("Se sincronizaron {$synced} registros.");

            Log::info('Sincronización de índices de búsqueda completada', [
                'synced_count' => $synced,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al sincronizar índices: '.$e->getMessage());
            Log::error('Error al sincronizar índices de búsqueda', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
