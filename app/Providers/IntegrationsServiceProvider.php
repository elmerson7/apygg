<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PushTransportInterface;
use App\Contracts\SmsSenderInterface;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\NullPaymentGateway;
use App\Integrations\Payments\StripePaymentGateway;
use App\Integrations\Push\FakePushTransport;
use App\Integrations\Push\FcmPushTransport;
use App\Integrations\Push\LogPushTransport;
use App\Integrations\Sms\FakeSmsSender;
use App\Integrations\Sms\HttpSmsSender;
use App\Integrations\Sms\LogSmsSender;
use App\Integrations\Sms\SmsChannel;
use App\Notifications\Channels\DeliveryChannel;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\Channels\PushChannel;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Único punto donde se elige la implementación de cada integración (docs/integrations.md §3):
 * `config('integrations.<servicio>.mode')` = `real|fake` (env `<SERVICIO>_MODE`).
 *
 * Binding `scoped`: una instancia por petición/job (Octane), nunca estado entre peticiones.
 * Los fakes registran sus llamadas en la instancia, así que un test las lee con `app(Interfaz::class)`.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindByMode(SmsSenderInterface::class, 'sms', [
            'fake' => fn () => new FakeSmsSender,
            'real' => fn () => match (config('integrations.sms.driver', 'http')) {
                'log' => new LogSmsSender,
                'http' => new HttpSmsSender([...(array) config('integrations.sms.http'), 'from' => config('integrations.sms.from')]),
                default => throw new InvalidArgumentException('SMS_DRIVER no válido: usa http o log'),
            },
        ]);

        // Pasarela de pago (docs/billing.md). `real` = Stripe (PAYMENTS_PROVIDER).
        $this->bindByMode(PaymentGatewayInterface::class, 'payments', [
            'fake' => fn () => new FakePaymentGateway,
            'null' => fn () => new NullPaymentGateway,
            'real' => fn () => match (config('integrations.payments.provider', 'stripe')) {
                'stripe' => new StripePaymentGateway((array) config('services.stripe')),
                default => throw new InvalidArgumentException('PAYMENTS_PROVIDER no válido: usa stripe (u otra pasarela registrada aquí, docs/billing.md §Añadir una pasarela)'),
            },
        ]);

        $this->bindByMode(PushTransportInterface::class, 'push', [
            'fake' => fn () => new FakePushTransport,
            'real' => fn () => match (config('integrations.push.driver', 'fcm')) {
                'log' => new LogPushTransport,
                'fcm' => new FcmPushTransport((array) config('integrations.push.fcm')),
                default => throw new InvalidArgumentException('PUSH_DRIVER no válido: usa fcm o log'),
            },
        ]);
    }

    public function boot(): void
    {
        // Canal `sms` de Notifications: `via() => ['sms']` + `toSms($notifiable)`.
        Notification::extend('sms', fn ($app) => $app->make(SmsChannel::class));

        // Canales del módulo de notificaciones (docs/notifications.md): `delivery` reparte una TypedNotification
        // entre mail / inbox (el `database` de la config) / push / sms con preferencias y reclamo idempotente.
        Notification::extend('delivery', fn ($app) => $app->make(DeliveryChannel::class));
        Notification::extend('inbox', fn ($app) => $app->make(InboxChannel::class));
        Notification::extend('push', fn ($app) => $app->make(PushChannel::class));

        // Webhooks entrantes: por IP y proveedor, para que un proveedor ruidoso no ahogue a los demás.
        RateLimiter::for('inbound-webhooks', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('integrations.inbound_webhooks.rate_limit_per_minute', 120))
        )->by($request->ip().'|'.$request->route('provider')));
    }

    /**
     * Enlaza `$abstract` con la fábrica que corresponda al `mode` configurado de `$service`.
     * Un proyecto que añade una integración la registra con esta misma llamada.
     *
     * @param  array<string, Closure>  $factories  `fake` y `real` => fábrica
     */
    protected function bindByMode(string $abstract, string $service, array $factories): void
    {
        $this->app->scoped($abstract, function () use ($service, $factories) {
            $mode = (string) config("integrations.{$service}.mode", 'fake');

            if (! isset($factories[$mode])) {
                throw new InvalidArgumentException("integrations.{$service}.mode debe ser real o fake, no «{$mode}»");
            }

            return $factories[$mode]();
        });
    }
}
