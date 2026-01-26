<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanPasswordResetTokensCommand extends Command
{
    protected $signature = 'auth:clean-reset-tokens';

    protected $description = 'Limpiar tokens de recuperación de contraseña expirados';

    public function handle(): int
    {
        $this->info('Limpiando tokens de recuperación de contraseña expirados...');

        try {
            // Los tokens expiran después de 1 hora (3600 segundos)
            $expiredTime = now()->subHour();

            $deleted = DB::table('password_reset_tokens')
                ->where('created_at', '<', $expiredTime)
                ->delete();

            $this->info("Se eliminaron {$deleted} tokens de recuperación de contraseña expirados.");

            Log::info('Limpieza de tokens de recuperación de contraseña completada', [
                'deleted_count' => $deleted,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al limpiar tokens de recuperación de contraseña: '.$e->getMessage());
            Log::error('Error al limpiar tokens de recuperación de contraseña', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
