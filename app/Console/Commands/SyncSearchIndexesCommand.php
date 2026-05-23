<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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
            $driver = config('scout.driver');
            if (! $driver) {
                $this->warn('Scout no está configurado. Saltando sincronización.');

                return Command::SUCCESS;
            }

            if ($driver === 'meilisearch') {
                if (! $this->meilisearchIsAvailable()) {
                    $this->warn('Meilisearch no está disponible. Saltando sincronización.');

                    return Command::SUCCESS;
                }

                $this->configureMeilisearchSettings();

                $this->info('Sincronizando settings de Scout...');
                Artisan::call('scout:sync-index-settings');
                $this->line(Artisan::output());
            }

            $synced = 0;

            $traits = class_uses_recursive(User::class);
            $hasSearchable = isset($traits[Searchable::class]) || isset($traits[\App\Traits\Searchable::class]);

            if ($hasSearchable) {
                $this->info('Sincronizando usuarios...');
                User::chunk(100, function ($users) use (&$synced) {
                    foreach ($users as $user) {
                        $user->searchable();
                        $synced++;
                    }
                });
            }

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

    private function meilisearchIsAvailable(): bool
    {
        try {
            $host = config('scout.meilisearch.host');
            $ch = curl_init($host.'/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function configureMeilisearchSettings(): void
    {
        $this->info('Configurando Meilisearch settings...');

        $client = new \Meilisearch\Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'));
        $index = $client->index('users');

        $index->updateSearchableAttributes([
            'name',
            'username',
            'email',
            'identity_document',
            'roles',
        ]);

        $index->updateDisplayedAttributes([
            'id',
            'name',
            'username',
            'email',
            'identity_document',
            'email_verified_at',
            'created_at',
            'updated_at',
            'roles',
        ]);

        $this->info('Meilisearch settings configurados.');
    }
}
