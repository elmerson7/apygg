<?php

namespace App\Notifications\Channels;

use App\Contracts\LocaleResolverInterface;
use App\Contracts\NotificationDeliveryServiceInterface;
use App\Contracts\NotificationPreferenceServiceInterface;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NotificationType;
use App\Notifications\TypedNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Canal `delivery`: reparte una `TypedNotification` entre los canales reales (mail, database, push, sms).
 *
 * Por cada canal del tipo: aplica opt-out y horas de silencio (salvo `mandatory`), comprueba que hay
 * destino, RECLAMA la entrega de forma idempotente (`NotificationDeliveryService::claim`) y la registra.
 * Un fallo en un canal no impide los demás; al final, si algún error es reintentable, se relanza para que
 * la cola reintente el job, y en el reintento solo se envía lo que no estaba enviado.
 *
 * La instancia del canal la cachea el `ChannelManager` (vive entre peticiones con Octane), así que no
 * guarda dependencias: se inyectan por método en `deliver()`.
 */
class DeliveryChannel
{
    /** Canal de configuración => canal registrado en Laravel. */
    private const DRIVERS = ['mail' => 'mail', 'database' => 'inbox', 'push' => 'push', 'sms' => 'sms'];

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof TypedNotification) {
            throw new InvalidArgumentException('El canal delivery solo envía TypedNotification a usuarios');
        }

        $tenants = app(TenantContext::class);

        // Si el Context de la cola no trajo el tenant (encolada tras un commit fuera de `run()`), se restaura aquí.
        if ($tenants->enabled() && $notification->tenantId() !== null && $tenants->id() !== $notification->tenantId()) {
            $tenant = Tenant::query()->find($notification->tenantId());

            if ($tenant === null) {
                return; // el tenant ya no existe: nada que entregar
            }

            $tenants->run($tenant, fn () => app()->call([$this, 'deliver'], ['user' => $notifiable, 'notification' => $notification]));

            return;
        }

        app()->call([$this, 'deliver'], ['user' => $notifiable, 'notification' => $notification]);
    }

    public function deliver(
        User $user,
        TypedNotification $notification,
        NotificationPreferenceServiceInterface $preferences,
        NotificationDeliveryServiceInterface $deliveries,
        LocaleResolverInterface $locales,
        ChannelManager $channels,
        TenantContext $tenants,
    ): void {
        $type = $notification->notificationType();
        $retry = null;

        $this->withLocale($locales->localeFor($user), function () use ($user, $notification, $type, $preferences, $deliveries, $channels, $tenants, &$retry): void {
            foreach ($type->channels as $channel) {
                $skip = $this->skipReason($user, $channel, $preferences, $tenants, $type);

                if ($skip !== null) {
                    $deliveries->markSkipped((string) $notification->id, (int) $user->getKey(), $type->key, $channel, $skip);

                    continue;
                }

                $delivery = $deliveries->claim((string) $notification->id, (int) $user->getKey(), $type->key, $channel);

                if ($delivery === null) {
                    continue; // ya enviada, o la tiene otro intento en curso
                }

                try {
                    $channels->driver(self::DRIVERS[$channel])->send($user, $notification);
                    $deliveries->markSent($delivery);
                } catch (Throwable $e) {
                    $deliveries->markFailed($delivery, $e);

                    if (! ($e instanceof IntegrationException && ! $e->isRetryable())) {
                        $retry ??= $e;
                    }
                }
            }
        });

        if ($retry instanceof Throwable) {
            throw $retry;
        }
    }

    private function withLocale(string $locale, \Closure $callback): void
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function skipReason(
        User $user,
        string $channel,
        NotificationPreferenceServiceInterface $preferences,
        TenantContext $tenants,
        NotificationType $type,
    ): ?string {
        if (! isset(self::DRIVERS[$channel])) {
            return 'unknown_channel';
        }

        if (! $preferences->isEnabled($user, $type, $channel)) {
            return 'opt_out';
        }

        if (! $type->mandatory && in_array($channel, (array) config('notifications.quiet_hours.channels', []), true) && $preferences->inQuietHours($user)) {
            return 'quiet_hours';
        }

        return match ($channel) {
            'mail' => blank($user->email) ? 'no_destination' : null,
            'sms' => blank($user->routeNotificationFor('sms')) ? 'no_destination' : null,
            'push' => UserDevice::query()->where('user_id', $user->getKey())->exists() ? null : 'no_destination',
            default => $tenants->enforcing() && $tenants->id() === null ? 'no_tenant_context' : null, // database (bandeja)
        };
    }
}
