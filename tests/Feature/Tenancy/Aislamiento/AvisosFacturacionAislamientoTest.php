<?php

use App\Contracts\BillingNoticeServiceInterface;
use App\Models\BillingNotice;
use App\Models\InboxNotification;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->bootBilling();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->adminA = $this->isoUser($this->a, 'admin');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $this->adminB = $this->isoUser($this->b, 'admin');
    $this->subA = $this->subscribeTo($this->a, $this->ownerA);
    $this->subB = $this->subscribeTo($this->b, $this->ownerB);
    // Los dos con la renovación en la ventana de aviso.
    $this->travelTo($this->subA->current_period_end->copy()->subDays(2));
});

function avisosDe($test, $tenant, string $type): array
{
    return $test->ctx->run($tenant, fn () => InboxNotification::query()->where('type', $type)->pluck('user_id')->all());
}

test('el barrido con varios tenants avisa a los destinatarios de cada uno y no mezcla', function () {
    expect(app(BillingNoticeServiceInterface::class)->sendUpcoming())->toMatchArray(['billing_renewal_upcoming' => 2])
        ->and(avisosDe($this, $this->a, 'billing_renewal_upcoming'))->toEqualCanonicalizing([$this->ownerA->id, $this->adminA->id])
        ->and(avisosDe($this, $this->b, 'billing_renewal_upcoming'))->toEqualCanonicalizing([$this->ownerB->id, $this->adminB->id]);
});

test('los reclamos de avisos quedan en el tenant de su suscripción y A no ve los de B', function () {
    app(BillingNoticeServiceInterface::class)->sendUpcoming();

    $this->ctx->run($this->a, fn () => expect(BillingNotice::query()->where('type', 'billing_renewal_upcoming')->pluck('subscription_id')->all())->toBe([$this->subA->id]));
    $this->ctx->run($this->b, fn () => expect(BillingNotice::query()->where('type', 'billing_renewal_upcoming')->pluck('subscription_id')->all())->toBe([$this->subB->id]));
    expect(DB::table('billing_notices')->where('subscription_id', $this->subA->id)->distinct()->pluck('tenant_id')->all())->toBe([$this->a->id])
        ->and(DB::table('billing_notices')->where('subscription_id', $this->subB->id)->distinct()->pluck('tenant_id')->all())->toBe([$this->b->id]);
});

test('un aviso ya enviado en A no impide ni altera el de B', function () {
    $notices = app(BillingNoticeServiceInterface::class);
    $this->ctx->run($this->a, fn () => $notices->sendUpcoming());
    $segundo = $notices->sendUpcoming();

    expect($segundo['billing_renewal_upcoming'])->toBe(0)
        ->and(DB::table('billing_notices')->where('type', 'billing_renewal_upcoming')->count())->toBe(2);

    // Anular solo el reclamo de B (borrarlo) deja intacto el de A y B vuelve a avisar una vez.
    DB::table('billing_notices')->where('type', 'billing_renewal_upcoming')->where('tenant_id', $this->b->id)->delete();
    expect($notices->sendUpcoming()['billing_renewal_upcoming'])->toBe(1)
        ->and(DB::table('billing_notices')->where('type', 'billing_renewal_upcoming')->where('tenant_id', $this->a->id)->count())->toBe(1);
});

test('un evento de cobro con el id de factura de B pero el tenant de A no avisa a nadie ni reclama nada', function () {
    $facturaB = $this->ctx->run($this->b, fn () => Invoice::query()->firstOrFail());
    $notices = app(BillingNoticeServiceInterface::class);
    $antes = DB::table('billing_notices')->count();
    $bandejaA = avisosDe($this, $this->a, 'billing_invoice_paid');

    expect($notices->invoicePaid($this->a->id, $facturaB->id))->toBeFalse()
        ->and(DB::table('billing_notices')->count())->toBe($antes)
        ->and(DB::table('billing_notices')->where('period_key', "paid:inv{$facturaB->id}")->pluck('tenant_id')->all())->toBe([$this->b->id])
        ->and(avisosDe($this, $this->a, 'billing_invoice_paid'))->toBe($bandejaA);
});

test('leer los reclamos sin contexto de tenant falla cerrado', function () {
    expect(fn () => BillingNotice::query()->count())->toThrow(MissingTenantContext::class);
});
