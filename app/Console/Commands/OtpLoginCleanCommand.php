<?php

namespace App\Console\Commands;

use App\Models\LoginOtp;
use Illuminate\Console\Command;

class OtpLoginCleanCommand extends Command
{
    protected $signature = 'otp-login:clean';

    protected $description = 'Borra los códigos de login ya usados o caducados (módulo otp-login)';

    public function handle(): int
    {
        $limit = now()->subDays(max(0, (int) config('otp-login.retention_days', 1)));

        $deleted = LoginOtp::query()
            ->where(fn ($q) => $q->where('expires_at', '<', $limit)->orWhere('consumed_at', '<', $limit))
            ->delete();

        $this->info("Códigos de login borrados: {$deleted}");

        return self::SUCCESS;
    }
}
