<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Notifications\TypedNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * NotificationService
 *
 * Único camino para notificar a usuarios (docs/notifications.md). Valida el tipo y delega en
 * `TypedNotification` (en cola, `afterCommit`), que decide canales, preferencias y silencio al enviar.
 */
class NotificationService implements NotificationServiceInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function notify(User|iterable $users, string $type, array $params = [], ?Tenant $tenant = null): void
    {
        $this->dispatch($users, $type, $params, $tenant, false);
    }

    public function notifyNow(User|iterable $users, string $type, array $params = [], ?Tenant $tenant = null): void
    {
        $this->dispatch($users, $type, $params, $tenant, true);
    }

    public function types(): array
    {
        return NotificationType::all();
    }

    /**
     * @param  User|iterable<User>  $users
     * @param  array<string, scalar|null>  $params
     */
    private function dispatch(User|iterable $users, string $type, array $params, ?Tenant $tenant, bool $now): void
    {
        NotificationType::find($type); // falla ya (422) si el tipo no está declarado

        $recipients = $users instanceof User ? [$users] : collect($users)->all();

        if ($recipients === []) {
            return;
        }

        $send = function () use ($recipients, $type, $params, $now): void {
            $notification = new TypedNotification($type, $params);

            $now
                ? Notification::sendNow($recipients, $notification)
                : Notification::send($recipients, $notification);
        };

        // La notificación captura el tenant en su constructor (también viaja en el Context de la cola).
        $tenant === null ? $send() : $this->tenants->run($tenant, $send);
    }
}
