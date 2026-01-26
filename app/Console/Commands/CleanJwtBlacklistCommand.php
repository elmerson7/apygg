<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanJwtBlacklistCommand extends Command
{
    protected $signature = 'jwt:clean-blacklist';

    protected $description = 'Limpiar tokens JWT expirados de la blacklist';

    public function handle(): int
    {
        $this->info('Limpiando tokens JWT expirados de la blacklist...');

        try {
            $deleted = DB::table('jwt_blacklist')
                ->where('expires_at', '<', now())
                ->delete();

            $this->info("Se eliminaron {$deleted} tokens expirados de la blacklist.");

            Log::info('Limpieza de JWT blacklist completada', [
                'deleted_count' => $deleted,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al limpiar JWT blacklist: '.$e->getMessage());
            Log::error('Error al limpiar JWT blacklist', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
