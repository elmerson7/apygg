<?php

use App\Contracts\BillingNoticeServiceInterface;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\SubscriptionCanceled;
use App\Models\InboxNotification;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->bootBilling();
    $this->owner = $this->isoUser($this->a, 'owner');
    $this->admin = $this->isoUser($this->a, 'admin');
    $this->member = $this->isoUser($this->a, 'member');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
    $this->sub = $this->subscribeTo($this->a, $this->owner);
    $this->fin = $this->sub->current_period_end;
});

function avBandeja($test, $tenant, string $type): array
{
    return $test->ctx->run($tenant, fn () => InboxNotification::query()->where('type', $type)->orderBy('id')->pluck('user_id')->all());
}

function avSvc(): BillingNoticeServiceInterface
{
    return app(BillingNoticeServiceInterface::class);
}

test('el alta cobrada avisa de factura pagada solo a dueño y admin, no al miembro', function () {
    expect(avBandeja($this, $this->a, 'billing_invoice_paid'))->toEqualCanonicalizing([$this->owner->id, $this->admin->id])
        ->and(avBandeja($this, $this->b, 'billing_invoice_paid'))->toBe([]);
});

test('el aviso de factura pagada no se duplica si el evento se repite', function () {
    $inv = $this->ctx->run($this->a, fn () => Invoice::query()->firstOrFail());
    event(new InvoicePaid($inv->id, $this->a->id, $this->sub->id, 'gateway'));
    event(new InvoicePaid($inv->id, $this->a->id, $this->sub->id, 'gateway'));

    expect(avBandeja($this, $this->a, 'billing_invoice_paid'))->toHaveCount(2);
});

test('una renovación rechazada avisa del cobro fallido y el último reintento avisa como último aviso', function () {
    $this->gateway()->declineNext('card_declined', times: 4);
    $this->travelTo($this->fin->copy()->addMinutes(5));
    $this->renewNow($this->a, $this->sub->id);

    expect(avBandeja($this, $this->a, 'billing_payment_failed'))->toEqualCanonicalizing([$this->owner->id, $this->admin->id]);

    foreach ([1, 3] as $dias) {
        $this->travelTo(now()->addDays($dias)->addMinutes(1));
        $this->renewNow($this->a, $this->sub->id);
    }

    expect(avBandeja($this, $this->a, 'billing_payment_failed'))->toHaveCount(4)       // intentos 1 y 2 x 2 destinatarios
        ->and(avBandeja($this, $this->a, 'billing_final_warning'))->toHaveCount(2);

    $this->travelTo(now()->addDays(5)->addMinutes(1));
    $this->renewNow($this->a, $this->sub->id);

    expect($this->fresh($this->a, $this->sub->id)->status)->toBe(SubscriptionStatus::Canceled)
        ->and(avBandeja($this, $this->a, 'billing_subscription_canceled'))->toHaveCount(2)
        ->and(avBandeja($this, $this->a, 'billing_final_warning'))->toHaveCount(2);
});

test('el cobro fallido del primer pago no genera aviso de morosidad', function () {
    $factura = $this->ctx->run($this->a, fn () => Invoice::query()->firstOrFail());
    avSvc()->paymentFailed($this->a->id, $factura->id, 1, now()->addDay()->toIso8601String());

    expect(avBandeja($this, $this->a, 'billing_payment_failed'))->toBe([]);
});

test('la suscripción vencida avisa una sola vez con el tipo expirada', function () {
    avSvc()->subscriptionEnded($this->a->id, $this->sub->id, 'expired', 'dunning_exhausted');
    avSvc()->subscriptionEnded($this->a->id, $this->sub->id, 'expired', 'dunning_exhausted');
    event(new SubscriptionCanceled($this->sub->id, $this->a->id, 'expired', 'dunning_exhausted'));

    expect(avBandeja($this, $this->a, 'billing_subscription_expired'))->toHaveCount(2);
});

test('la renovación próxima avisa dentro de la ventana, una vez y solo en planes de pago', function () {
    $this->travelTo($this->fin->copy()->subDays(4));
    expect(avSvc()->sendUpcoming())->toMatchArray(['billing_renewal_upcoming' => 0]);

    $this->travelTo($this->fin->copy()->subDays(2));
    expect(avSvc()->sendUpcoming())->toMatchArray(['billing_renewal_upcoming' => 1])
        ->and(avSvc()->sendUpcoming())->toMatchArray(['billing_renewal_upcoming' => 0])
        ->and(avBandeja($this, $this->a, 'billing_renewal_upcoming'))->toHaveCount(2)
        ->and(avBandeja($this, $this->b, 'billing_renewal_upcoming'))->toBe([]);
});

test('la renovación próxima no avisa si hay baja programada ni en el plan gratuito', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->whereKey($this->sub->id)->update(['cancel_at_period_end' => true]));
    $this->travelTo($this->fin->copy()->subDays(2));
    expect(avSvc()->sendUpcoming()['billing_renewal_upcoming'])->toBe(0);

    $this->ctx->run($this->a, fn () => Subscription::query()->whereKey($this->sub->id)->update(['cancel_at_period_end' => false, 'unit_amount_minor' => 0]));
    expect(avSvc()->sendUpcoming()['billing_renewal_upcoming'])->toBe(0);
});

test('el fin de prueba avisa N días antes, una vez, y cada tenant a los suyos', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->whereKey($this->sub->id)->update(['status' => 'trialing', 'trial_ends_at' => now()->addDays(2)]));
    $ownerB = $this->ownerB;
    Plan::query()->where('code', 'pro')->update(['trial_days' => 14]);
    $this->subscribeTo($this->b, $ownerB, token: null);   // prueba de 14 días: fuera de la ventana

    expect(avSvc()->sendUpcoming())->toMatchArray(['billing_trial_ending' => 1])
        ->and(avSvc()->sendUpcoming())->toMatchArray(['billing_trial_ending' => 0])
        ->and(avBandeja($this, $this->a, 'billing_trial_ending'))->toEqualCanonicalizing([$this->owner->id, $this->admin->id])
        ->and(avBandeja($this, $this->b, 'billing_trial_ending'))->toBe([]);
});

test('un tenant suspendido no recibe avisos de barrido', function () {
    $this->travelTo($this->fin->copy()->subDays(2));
    $this->a->forceFill(['status' => 'suspended'])->save();

    expect(avSvc()->sendUpcoming()['billing_renewal_upcoming'])->toBe(0);
});

test('un tenant sin dueños ni admins no reclama el aviso y sale cuando los haya', function () {
    DB::table('tenant_user')->where('tenant_id', $this->a->id)->whereIn('role', ['owner', 'admin'])->update(['role' => 'member']);
    $this->travelTo($this->fin->copy()->subDays(2));

    expect(avSvc()->sendUpcoming()['billing_renewal_upcoming'])->toBe(0)
        ->and(DB::table('billing_notices')->where('type', 'billing_renewal_upcoming')->count())->toBe(0);

    DB::table('tenant_user')->where('tenant_id', $this->a->id)->where('user_id', $this->owner->id)->update(['role' => 'owner']);
    expect(avSvc()->sendUpcoming()['billing_renewal_upcoming'])->toBe(1);
});

test('el comando billing:notify es idempotente', function () {
    $this->travelTo($this->fin->copy()->subDays(2));
    $this->artisan('billing:notify')->assertSuccessful();
    $this->artisan('billing:notify')->assertSuccessful();

    expect(DB::table('billing_notices')->where('type', 'billing_renewal_upcoming')->count())->toBe(1);
});

test('el aviso de cobro fallido es obligatorio: ignora la preferencia desactivada', function () {
    expect(config('notifications.types.billing_payment_failed.mandatory'))->toBeTrue()
        ->and(config('notifications.types.billing_final_warning.mandatory'))->toBeTrue()
        ->and(config('notifications.types.billing_trial_ending.mandatory'))->toBeNull();
});
