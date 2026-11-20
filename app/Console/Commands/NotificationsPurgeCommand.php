<?php

namespace App\Console\Commands;

use App\Contracts\DeviceServiceInterface;
use App\Contracts\InboxServiceInterface;
use App\Contracts\NotificationDeliveryServiceInterface;
use Illuminate\Console\Command;

/**
 * Retención del módulo de notificaciones: entregas terminadas, bandeja leída y dispositivos sin verse.
 * Cada plazo viene de `config/notifications.php` (0 = no purgar). Programado a diario.
 * Uso: php artisan notifications:purge
 */
class NotificationsPurgeCommand extends Command
{
    protected $signature = 'notifications:purge';

    protected $description = 'Purgar entregas, bandeja leída y dispositivos push antiguos';

    public function handle(
        NotificationDeliveryServiceInterface $deliveries,
        InboxServiceInterface $inbox,
        DeviceServiceInterface $devices,
    ): int {
        $days = (int) config('notifications.deliveries.retention_days', 90);
        $this->info('Entregas borradas: '.($days > 0 ? $deliveries->purge($days) : 0));

        $days = (int) config('notifications.inbox.retention_days', 180);
        $this->info('Notificaciones leídas borradas: '.($days > 0 ? $inbox->purgeRead($days) : 0));

        $days = (int) config('notifications.devices.stale_days', 180);
        $this->info('Dispositivos inactivos borrados: '.($days > 0 ? $devices->purgeStale($days) : 0));

        return self::SUCCESS;
    }
}
