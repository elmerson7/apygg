<?php

namespace App\Console\Commands;

use App\Contracts\NotificationDeliveryServiceInterface;
use Illuminate\Console\Command;

/**
 * Marca como fallidas las entregas que quedaron «enviando» (el job murió sin terminarlas).
 * Programado cada 10 minutos. Uso: php artisan notifications:sweep [--minutes=15]
 */
class NotificationsSweepCommand extends Command
{
    protected $signature = 'notifications:sweep {--minutes= : Minutos sin terminar para darla por abandonada (por defecto, notifications.deliveries.stuck_minutes)}';

    protected $description = 'Marcar como fallidas las entregas de notificaciones atascadas';

    public function handle(NotificationDeliveryServiceInterface $deliveries): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('notifications.deliveries.stuck_minutes', 15));

        $this->info('Entregas atascadas marcadas como fallidas: '.$deliveries->sweepStuck(max(1, $minutes)));

        return self::SUCCESS;
    }
}
