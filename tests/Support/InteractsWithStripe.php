<?php

namespace Tests\Support;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Pasarela REAL de Stripe (`StripePaymentGateway`) contra el simulador `StripeFake` (sin red) y webhooks firmados.
 * Va junto a `InteractsWithBilling`: llama a `useStripe()` DESPUÉS de `bootBilling()`.
 */
trait InteractsWithStripe
{
    public StripeFake $stripe;

    public function useStripe(): void
    {
        config([
            'integrations.payments.mode' => 'real',
            'integrations.payments.provider' => 'stripe',
            'services.stripe.secret' => 'sk_test_abcdefghijkl',
            'services.stripe.api_base' => 'https://api.stripe.test/v1',
            'services.stripe.webhook_secret' => 'whsec_test_secret',
            'services.stripe.webhook_tolerance' => 300,
        ]);
        app()->forgetInstance(PaymentGatewayInterface::class);
        $this->stripe = StripeFake::install();
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $over  `secret`, `ts`, `sig`, `body`, `drop`
     */
    public function stripeWebhook(string $type, array $object, ?string $eventId = null, array $over = []): TestResponse
    {
        static $seq = 0;
        $event = ['id' => $eventId ?? 'evt_'.++$seq, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
        $body = $over['body'] ?? json_encode($event, JSON_THROW_ON_ERROR);
        $ts = $over['ts'] ?? time();
        $sig = $over['sig'] ?? "t={$ts},v1=".hash_hmac('sha256', "{$ts}.{$body}", $over['secret'] ?? 'whsec_test_secret');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig];

        foreach ($over['drop'] ?? [] as $h) {
            unset($server[$h]);
        }

        return $this->call('POST', '/webhooks/inbound/stripe', [], [], [], $server, $body);
    }

    public function stripePayment(Tenant $tenant, ?int $id = null): Payment
    {
        return $this->ctx->run($tenant, fn () => $id !== null ? Payment::query()->findOrFail($id) : Payment::query()->orderByDesc('id')->firstOrFail());
    }
}
