<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationDeliveryServiceInterface;
use App\Models\NotificationDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationDeliveryService implements NotificationDeliveryServiceInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function claim(string $notificationId, int $userId, string $type, string $channel): ?NotificationDelivery
    {
        $now = now();

        // 1) La fila existe (el UNIQUE decide quién la crea: sin comprobar antes con exists()).
        NotificationDelivery::query()->insertOrIgnore([[
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'tenant_id' => $this->tenants->id(),
            'type' => $type,
            'channel' => $channel,
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        // 2) Reclamo atómico: un UPDATE condicionado; solo uno de los concurrentes ve 1 fila afectada.
        $claimed = NotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where(function ($query) use ($now) {
                $query->whereIn('status', [NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_FAILED])
                    ->orWhere(fn ($q) => $q
                        ->where('status', NotificationDelivery::STATUS_SENDING)
                        ->where('claimed_at', '<', $now->copy()->subSeconds((int) config('notifications.deliveries.lease_seconds', 120))));
            })
            ->update([
                'status' => NotificationDelivery::STATUS_SENDING,
                'claimed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'error' => null,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        return NotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->first();
    }

    public function markSent(NotificationDelivery $delivery): void
    {
        $this->finish($delivery, NotificationDelivery::STATUS_SENT, ['sent_at' => now(), 'error' => null]);
    }

    public function markFailed(NotificationDelivery $delivery, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? class_basename($error).': '.$error->getMessage() : $error;

        $this->finish($delivery, NotificationDelivery::STATUS_FAILED, ['error' => mb_substr($message, 0, 1000)]);
    }

    public function markSkipped(string $notificationId, int $userId, string $type, string $channel, string $reason): void
    {
        $now = now();

        NotificationDelivery::query()->insertOrIgnore([[
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'tenant_id' => $this->tenants->id(),
            'type' => $type,
            'channel' => $channel,
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'attempts' => 0,
            'skipped_reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }

    public function sweepStuck(int $minutes): int
    {
        return NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_SENDING)
            ->where('claimed_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'error' => 'Entrega abandonada: el job murió o se agotó sin terminarla',
                'updated_at' => now(),
            ]);
    }

    public function purge(int $days): int
    {
        return NotificationDelivery::query()
            ->whereIn('status', [NotificationDelivery::STATUS_SENT, NotificationDelivery::STATUS_FAILED, NotificationDelivery::STATUS_SKIPPED])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(NotificationDelivery $delivery, string $status, array $attributes): void
    {
        // Condicionado a seguir `sending`: si otro intento la reclamó tras caducar el arrendamiento, no la pisa.
        NotificationDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', NotificationDelivery::STATUS_SENDING)
            ->update([...$attributes, 'status' => $status, 'updated_at' => now()]);
    }
}
