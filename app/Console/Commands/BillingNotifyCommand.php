<?php

namespace App\Console\Commands;

use App\Contracts\BillingNoticeServiceInterface;
use Illuminate\Console\Command;

/**
 * Avisos con antelación (docs/billing.md §Avisos): fin de prueba y renovación próxima. Idempotente: un aviso sale una sola
 * vez por suscripción y periodo, aunque el comando corra varias veces o a la vez.
 *
 * Uso: php artisan billing:notify
 */
class BillingNotifyCommand extends Command
{
    protected $signature = 'billing:notify';

    protected $description = 'Enviar los avisos de fin de prueba y renovación próxima a dueños y administradores';

    public function handle(BillingNoticeServiceInterface $notices): int
    {
        foreach ($notices->sendUpcoming() as $type => $count) {
            $this->line("{$type}: {$count}");
        }

        return self::SUCCESS;
    }
}
