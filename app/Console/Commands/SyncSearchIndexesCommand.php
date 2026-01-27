<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSearchIndexesCommand extends Command
{
    protected $signature = 'search:sync-indexes';

    protected $description = 'Sincronizar índices de búsqueda con Meilisearch';

    public function handle(): int
    {
        $this->info('Sincronizando índices de búsqueda...');

        try {
            // Verificar si Scout está configurado
            if (! config('scout.driver')) {
                $this->warn('Scout no está configurado. Saltando sincronización.');

                return Command::SUCCESS;
            }

            $synced = 0;

            // Sincronizar usuarios si tienen el trait Searchable
            $traits = class_uses_recursive(User::class);
            $hasSearchable = isset($traits[\Laravel\Scout\Searchable::class]) || isset($traits[\App\Traits\Searchable::class]);

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
