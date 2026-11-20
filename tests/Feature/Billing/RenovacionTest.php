<?php

use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\SubscriptionCanceled;
use App\Events\Billing\SubscriptionRenewed;
use App\Exceptions\BillingException;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->bootBilling();
    $this->owner = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->travelTo('2026-11-20 12:00:00');
    $this->sub = $this->subscribeTo($this->a, $this->owner);   // pro mensual, activa: 20/11 -> 20/12
    $this->fin = $this->sub->current_period_end;
});

/** Para `toThrow`: comprueba el `code` estable de la excepción (no el mensaje, que es para humanos). */
function rnCodigo(string $code, ?array $extensiones = null): Closure
{
    return function (BillingException $e) use ($code, $extensiones) {
        expect($e->errorCode())->toBe($code);
        if ($extensiones !== null) {
            expect($e->getExtensions())->toMatchArray($extensiones);
        }
    };
}

function rnFacturas($test): array
{
    return $test->ctx->run($test->a, fn () => Invoice::query()->orderBy('id')->get()->all());
}

test('una renovación vencida emite la factura del nuevo periodo, la cobra y avanza el periodo', function () {
    Event::fake([InvoicePaid::class, SubscriptionRenewed::class]);
    $this->travelTo($this->fin->copy()->addMinutes(5));

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('renewed');

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::Active)
        ->and($s->current_period_start->equalTo($this->fin))->toBeTrue()
        ->and($s->current_period_end->toDateTimeString())->toBe('2027-01-20 12:00:00')
        ->and($s->renewal_attempts)->toBe(0);

    [$alta, $renovacion] = rnFacturas($this);
    expect($renovacion->number)->toBe('F-2026-000002')
        ->and($renovacion->status)->toBe(InvoiceStatus::Paid)
        ->and($renovacion->billing_reason->value)->toBe('subscription_cycle')
        ->and($renovacion->period_start->equalTo($this->fin))->toBeTrue()
        ->and($renovacion->total->amountMinor)->toBe(2900);
    Event::assertDispatched(InvoicePaid::class);
    Event::assertDispatched(SubscriptionRenewed::class);
});

test('antes del fin del periodo no se hace nada', function () {
    $this->travelTo($this->fin->copy()->subMinute());

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('not_due')->and(rnFacturas($this))->toHaveCount(1);
});

test('renovar dos veces el mismo periodo no duplica la factura ni el cobro', function () {
    $this->travelTo($this->fin->copy()->addMinute());

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('renewed');
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('not_due');
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('not_due');

    expect(rnFacturas($this))->toHaveCount(2)->and($this->gateway()->calls('charge'))->toHaveCount(2);   // alta + 1 renovación
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(2));
});

test('una renovación rechazada pasa a past_due, conserva el plan y programa el primer reintento a +1 día', function () {
    Event::fake([PaymentFailed::class]);
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext('card_declined');

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('payment_failed');

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::PastDue)
        ->and($s->renewal_attempts)->toBe(1)
        ->and($s->last_failure_code)->toBe('card_declined')
        ->and($s->next_retry_at->toDateTimeString())->toBe(now()->addDay()->toDateTimeString())
        ->and($s->plan_code)->toBe('pro')
        ->and(rnFacturas($this)[1]->status)->toBe(InvoiceStatus::Open);
    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->attempt === 1 && $e->nextRetryAt !== null && $e->failureCode === 'card_declined');

    // Sigue teniendo los límites de su plan mientras dura la gracia
    expect(app(PlanLimitsInterface::class)->limit('max_members', $this->a->id))->toBe(25);
});

test('antes del siguiente reintento no se vuelve a cobrar', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext();
    $this->renewNow($this->a, $this->sub->id);

    $this->travel(23)->hours();
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('not_due')->and($this->gateway()->calls('charge'))->toHaveCount(2);
});

test('el calendario de reintentos 1, 3 y 5 días se cumple y al agotarse la suscripción se cancela y la factura queda incobrable', function () {
    Event::fake([SubscriptionCanceled::class]);
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext('card_declined', 4);

    $this->renewNow($this->a, $this->sub->id);                                              // fallo 1 -> +1 día
    expect($this->fresh($this->a, $this->sub->id)->next_retry_at->toDateTimeString())->toBe(now()->addDays(1)->toDateTimeString());

    $this->travelTo(now()->addDays(1)->addMinute());
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('payment_failed');       // fallo 2 -> +3 días
    expect($this->fresh($this->a, $this->sub->id)->next_retry_at->toDateTimeString())->toBe(now()->addDays(3)->toDateTimeString());

    $this->travelTo(now()->addDays(3)->addMinute());
    $this->renewNow($this->a, $this->sub->id);                                              // fallo 3 -> +5 días
    expect($this->fresh($this->a, $this->sub->id)->next_retry_at->toDateTimeString())->toBe(now()->addDays(5)->toDateTimeString());

    $this->travelTo(now()->addDays(5)->addMinute());
    $this->renewNow($this->a, $this->sub->id);                                              // fallo 4 -> agotado

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::Canceled)->and($s->ended_at)->not->toBeNull()->and($s->next_retry_at)->toBeNull()
        ->and(rnFacturas($this)[1]->status)->toBe(InvoiceStatus::Uncollectible)
        ->and(rnFacturas($this))->toHaveCount(2);                                           // la misma factura en todos los intentos
    Event::assertDispatched(SubscriptionCanceled::class, fn ($e) => $e->reason === 'dunning_exhausted' && $e->status === 'canceled');
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'failed')->count())->toBe(4));
});

test('con dunning.final_status=expired los reintentos agotados dejan la suscripción expirada', function () {
    config(['billing.dunning.final_status' => 'expired', 'billing.dunning.retry_days' => [1]]);
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext('card_declined', 2);

    $this->renewNow($this->a, $this->sub->id);
    $this->travelTo(now()->addDays(1)->addMinute());
    $this->renewNow($this->a, $this->sub->id);

    expect($this->fresh($this->a, $this->sub->id)->status)->toBe(SubscriptionStatus::Expired);
});

test('un reintento exitoso recupera la suscripción con la MISMA factura y el periodo contratado', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext();
    $this->renewNow($this->a, $this->sub->id);

    $this->travelTo(now()->addDays(1)->addMinute());
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('renewed');

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::Active)->and($s->renewal_attempts)->toBe(0)->and($s->next_retry_at)->toBeNull()
        ->and($s->current_period_start->equalTo($this->fin))->toBeTrue()
        ->and(rnFacturas($this))->toHaveCount(2)->and(rnFacturas($this)[1]->status)->toBe(InvoiceStatus::Paid);
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('invoice_id', rnFacturas($this)[1]->id)->orderBy('attempt')->pluck('status')->map->value->all())->toBe(['failed', 'succeeded']));
});

test('un reintento pedido por el cliente que falla no consume reintentos del calendario', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext();
    $this->renewNow($this->a, $this->sub->id);
    $abierta = rnFacturas($this)[1]->id;

    $this->gateway()->declineNext('card_declined');
    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', "/tenant/billing/invoices/{$abierta}/pay")->assertStatus(402);

    expect($this->fresh($this->a, $this->sub->id)->renewal_attempts)->toBe(1);
    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', "/tenant/billing/invoices/{$abierta}/pay")->assertOk();
    expect($this->fresh($this->a, $this->sub->id)->status)->toBe(SubscriptionStatus::Active);
});

test('una baja al final del periodo se ejecuta en la renovación sin emitir factura', function () {
    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', '/tenant/billing/subscription/cancel')->assertOk();
    $this->travelTo($this->fin->copy()->addMinute());

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('canceled');

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::Canceled)->and($s->ended_at)->not->toBeNull()->and(rnFacturas($this))->toHaveCount(1);
});

test('una bajada de plan programada se aplica al renovar: se factura el precio nuevo y se cambia el plan', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->publishPlan('enterprise');
    $enterprise = $this->subscribeTo($this->a, $this->owner, 'enterprise');
    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', '/tenant/billing/subscription/change', ['plan' => 'pro', 'interval' => 'month'])->assertOk();
    $this->travelTo($enterprise->current_period_end->copy()->addMinute());

    expect($this->renewNow($this->a, $enterprise->id)->value)->toBe('renewed');

    $s = $this->fresh($this->a, $enterprise->id);
    $factura = collect(rnFacturas($this))->last();
    expect($s->plan_code)->toBe('pro')->and($s->unit_amount->amountMinor)->toBe(2900)->and($s->scheduled_plan_price_id)->toBeNull()
        ->and($factura->total->amountMinor)->toBe(2900);
});

test('un plan gratuito renueva el periodo sin factura ni cobro', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $free = $this->subscribeTo($this->a, $this->owner, 'free', token: null);
    $this->travelTo($free->current_period_end->copy()->addMinute());

    expect($this->renewNow($this->a, $free->id)->value)->toBe('renewed_free');
    expect($this->fresh($this->a, $free->id)->current_period_start->equalTo($free->current_period_end))->toBeTrue();
});

test('al terminar la prueba se cobra el plan; sin método de pago pasa a past_due', function () {
    Plan::query()->where('code', 'pro')->update(['trial_days' => 14]);
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $conTarjeta = $this->subscribeTo($this->a, $this->owner);
    expect($conTarjeta->status)->toBe(SubscriptionStatus::Trialing);

    $this->travelTo($conTarjeta->trial_ends_at->copy()->addMinute());
    expect($this->renewNow($this->a, $conTarjeta->id)->value)->toBe('renewed');
    expect($this->fresh($this->a, $conTarjeta->id)->status)->toBe(SubscriptionStatus::Active);

    // Otra empresa sin tarjeta
    $this->travelTo('2026-11-20 12:00:00');
    $sinTarjeta = $this->subscribeTo($this->b, $this->ownerB, token: null);
    $this->travelTo($sinTarjeta->trial_ends_at->copy()->addMinute());
    expect($this->renewNow($this->b, $sinTarjeta->id)->value)->toBe('payment_failed');
    expect($this->fresh($this->b, $sinTarjeta->id)->status)->toBe(SubscriptionStatus::PastDue)->and($this->fresh($this->b, $sinTarjeta->id)->last_failure_code)->toBe('no_payment_method');
});

test('un alta sin pagar caduca a las 24 h: la suscripción expira y la factura se anula', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->gateway()->declineNext();
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(BillingException::class);
    $inc = $this->ctx->run($this->a, fn () => Subscription::query()->latest('id')->first());

    $this->travel(23)->hours();
    expect($this->renewNow($this->a, $inc->id)->value)->toBe('not_due');
    $this->travel(2)->hours();
    expect($this->renewNow($this->a, $inc->id)->value)->toBe('expired');

    expect($this->fresh($this->a, $inc->id)->status)->toBe(SubscriptionStatus::Expired)
        ->and(collect(rnFacturas($this))->last()->status)->toBe(InvoiceStatus::Void);
});

test('un error transitorio de la pasarela deja el cobro pendiente y se retoma con LA MISMA clave de idempotencia', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->failNext(TransientIntegrationError::class);

    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('retrying');
    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::Active)->and($s->renewal_attempts)->toBe(0);
    $pago = $this->ctx->run($this->a, fn () => Payment::query()->latest('id')->first());
    expect($pago->status->value)->toBe('pending');

    $this->travel(2)->minutes();   // el backoff (5 min) no ha vencido
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('in_progress');

    $this->travel(5)->minutes();
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('renewed');

    $claves = collect($this->gateway()->charges())->pluck('idempotencyKey')->all();
    expect(array_slice($claves, -2))->toBe([$pago->idempotency_key, $pago->idempotency_key])
        ->and($this->ctx->run($this->a, fn () => Payment::query()->where('invoice_id', $pago->invoice_id)->count()))->toBe(1);
});

test('tras agotar los intentos transitorios el cobro se da por fallido (gateway_unavailable) y entra en el calendario de morosidad', function () {
    config(['billing.payment.max_transient_tries' => 2]);
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->failNext(TransientIntegrationError::class, 2);

    $this->renewNow($this->a, $this->sub->id);
    $this->travel(6)->minutes();
    // El último envío también dio timeout: NO se da por fallido en esa misma pasada (la pasarela pudo seguir procesándolo).
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('retrying');
    $envios = count($this->gateway()->calls('charge'));

    $this->travel(6)->minutes();
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('payment_failed');
    // La pasada que lo da por fallido solo consulta (confirma que no existe): no reenvía.
    expect($this->gateway()->calls('charge'))->toHaveCount($envios);

    $s = $this->fresh($this->a, $this->sub->id);
    expect($s->status)->toBe(SubscriptionStatus::PastDue)->and($s->last_failure_code)->toBe('gateway_unavailable');
});

test('billing:renew procesa las suscripciones vencidas de todos los tenants, cada una dentro de su tenant', function () {
    $this->travelTo('2026-11-20 12:00:00');
    $otra = $this->subscribeTo($this->b, $this->ownerB);   // pro de B, también vencerá
    $this->ctx->clear();
    $this->travelTo($this->fin->copy()->addMinutes(2));

    $this->artisan('billing:renew')->assertSuccessful();

    expect($this->fresh($this->a, $this->sub->id)->current_period_start->equalTo($this->fin))->toBeTrue()
        ->and($this->fresh($this->b, $otra->id)->current_period_start->equalTo($otra->current_period_end))->toBeTrue();
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(2));
    $this->ctx->run($this->b, fn () => expect(Invoice::query()->count())->toBe(2));

    $this->artisan('billing:renew')->assertSuccessful();   // idempotente
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(2));
});

test('due() solo devuelve lo que tiene algo que hacer', function () {
    $svc = app(RenewalServiceInterface::class);
    expect($svc->due(50))->toBe([]);

    $this->travelTo($this->fin->copy()->addMinute());
    expect($svc->due(50))->toBe([['id' => $this->sub->id, 'tenant_id' => $this->a->id]]);
});

test('PlanLimits lee el plan de la suscripción viva y vuelve a tenants.plan al terminar', function () {
    $limits = app(PlanLimitsInterface::class);
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->a->forceFill(['plan' => 'free'])->save();
    $limits->refresh($this->a->id);
    expect($limits->limit('max_members', $this->a->id))->toBe(3);

    $this->subscribeTo($this->a, $this->owner, 'pro');
    expect($limits->limit('max_members', $this->a->id))->toBe(25);

    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertOk();
    expect($limits->limit('max_members', $this->a->id))->toBe(3);
});

test('cambiar el alcance de la numeración con facturas ya emitidas se rechaza al emitir y al arrancar billing:renew', function () {
    config(['billing.invoice.sequence_scope' => 'tenant']);   // las facturas ya emitidas son `global` (por defecto)
    $this->travelTo($this->fin->copy()->addMinute());

    expect(fn () => $this->renewNow($this->a, $this->sub->id))->toThrow(rnCodigo('invoice_numbering_scope_changed'));
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(1));

    $this->artisan('billing:renew')->assertFailed();
});

test('billing:renew omite los tenants no activos y lo registra; con renew_inactive_tenants=true sí los factura', function () {
    $this->a->forceFill(['status' => TenantStatus::Suspended])->save();
    $this->ctx->clear();
    $this->travelTo($this->fin->copy()->addMinute());

    $this->artisan('billing:renew')->expectsOutputToContain('skipped_inactive_tenant: 1')->assertSuccessful();
    expect($this->fresh($this->a, $this->sub->id)->current_period_start->equalTo($this->fin))->toBeFalse();
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(1));

    config(['billing.renew_inactive_tenants' => true]);
    $this->artisan('billing:renew')->assertSuccessful();
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(2));
});

test('una cancelación inmediata con el cobro en vuelo reembolsa el cobro con clave idempotente, lo registra y lo audita', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $owner = $this->owner;
    $a = $this->a;
    $this->gateway()->onCharge(function () use ($a, $owner) {
        $this->gateway()->onCharge(null);
        app(SubscriptionServiceInterface::class)->cancel($a, $owner, true);   // la baja llega mientras la pasarela cobra
    });

    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(rnCodigo('invoice_not_open', ['refund' => 'refunded']));

    $this->ctx->run($this->a, function () {
        $pago = Payment::query()->orderByDesc('id')->first();
        expect($pago->status->value)->toBe('refunded')->and($pago->refunded_at)->not->toBeNull()->and($pago->refund_id)->not->toBeNull()
            ->and(Invoice::query()->orderByDesc('id')->first()->status)->toBe(InvoiceStatus::Void)
            ->and(Subscription::query()->latest('id')->first()->status)->toBe(SubscriptionStatus::Canceled);

        $reembolsos = $this->gateway()->calls('refund');
        expect($reembolsos)->toHaveCount(1)
            ->and($reembolsos[0]['payload']->idempotencyKey)->toBe('refund-'.$pago->id)
            ->and($reembolsos[0]['payload']->amount->amountMinor)->toBe(2900);
        expect(AuditLog::query()->where('event', 'billing.payment-refunded')->count())->toBe(1);

        // Idempotente: repetirlo no vuelve a llamar a la pasarela
        expect(app(PaymentProcessorInterface::class)->refund($pago->id))->toBeFalse();
    });
    expect($this->gateway()->calls('refund'))->toHaveCount(1);
});

test('si el reembolso falla por la pasarela, billing:renew lo reintenta con la misma clave hasta lograrlo', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $owner = $this->owner;
    $a = $this->a;
    $gateway = $this->gateway();
    $gateway->onCharge(function () use ($a, $owner, $gateway) {
        $gateway->onCharge(null);
        app(SubscriptionServiceInterface::class)->cancel($a, $owner, true);
        $gateway->failNext(TransientIntegrationError::class);   // el reembolso inmediato cae
    });
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(rnCodigo('invoice_not_open', ['refund' => 'pending']));

    $pago = $this->ctx->run($this->a, fn () => Payment::query()->orderByDesc('id')->first());
    expect($pago->status->value)->toBe('succeeded')->and($pago->refunded_at)->toBeNull();

    $this->ctx->clear();
    $this->artisan('billing:renew')->expectsOutputToContain('refunded: 1')->assertSuccessful();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->findOrFail($pago->id)->status->value)->toBe('refunded'));
    $claves = collect($gateway->calls('refund'))->map(fn ($c) => $c['payload']->idempotencyKey)->all();
    expect($claves)->toBe(['refund-'.$pago->id, 'refund-'.$pago->id]);
});

test('una factura anulada sin cobro no genera reembolso y un pago manual nunca se reembolsa por la pasarela', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->gateway()->declineNext();
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(BillingException::class);
    $this->isoCall($this->isoHeaders($this->owner, $this->a), 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertOk();

    expect(app(PaymentProcessorInterface::class)->orphanedPayments(50))->toBe([])
        ->and($this->gateway()->calls('refund'))->toBe([]);
});

test('el pago manual no pisa un cobro de la pasarela a medias: 409 y billing:renew lo resuelve con la misma clave sin cobrar dos veces', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->failNext(TransientIntegrationError::class);
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('retrying');

    $factura = $this->ctx->run($this->a, fn () => Invoice::query()->orderByDesc('id')->first());
    $this->travelTo(now()->addMinutes(10));   // arrendamiento y espera vencidos: el intento sigue `pending` y pudo haber cobrado

    expect(fn () => app(InvoiceServiceInterface::class)->markPaidManually($factura->id, $this->owner, 'TRF-1', 'Transferencia recibida'))
        ->toThrow(rnCodigo('payment_pending'));

    $this->ctx->clear();
    $this->artisan('billing:renew')->assertSuccessful();

    $this->ctx->run($this->a, function () use ($factura) {
        expect(Invoice::query()->findOrFail($factura->id)->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->where('invoice_id', $factura->id)->get()->map(fn ($p) => $p->method.':'.$p->status->value)->all())->toBe(['gateway:succeeded']);
    });
    $claves = collect($this->gateway()->charges())->slice(1)->map(fn ($c) => $c->idempotencyKey)->values()->all();
    expect($claves)->toBe(["inv-{$factura->id}-a1", "inv-{$factura->id}-a1"]);
});

test('un cobro a medias sobre una factura anulada después: si la pasarela confirma que nunca llegó, NO se cobra para reembolsar', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->gateway()->failNext(TransientIntegrationError::class);
    expect(fn () => $this->subscribeTo($this->a, $this->owner, token: null))->toThrow(rnCodigo('payment_pending'));

    $this->ctx->run($this->a, fn () => app(SubscriptionServiceInterface::class)->cancel($this->a, $this->owner, true));
    $pago = $this->ctx->run($this->a, fn () => Payment::query()->orderByDesc('id')->first());
    expect($pago->status->value)->toBe('pending');
    $envios = count($this->gateway()->calls('charge'));
    Event::fake([PaymentFailed::class]);

    $this->travelTo(now()->addMinutes(10));
    $this->ctx->clear();
    $this->artisan('billing:renew')->expectsOutputToContain('payment_failed: 1')->assertSuccessful();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->findOrFail($pago->id)->status->value)->toBe('failed')
        ->and(Payment::query()->findOrFail($pago->id)->failure_code)->toBe('invoice_void'));
    // Se consultó por la clave, no se reenvió el cobro, no hubo reembolso ni aviso de «pago fallido» por una factura anulada.
    expect($this->gateway()->calls('charge'))->toHaveCount($envios)->and($this->gateway()->calls('findCharge'))->toHaveCount(1)
        ->and($this->gateway()->calls('refund'))->toBe([]);
    Event::assertNotDispatched(PaymentFailed::class);

    // Resuelto: ya no vuelve a salir en el barrido.
    expect(app(RenewalServiceInterface::class)->due(50))->toBe([]);
});

test('si una baja inmediata deja incobrable la factura con un reintento en vuelo y la pasarela cobra, la factura queda pagada (el cobro no se pierde)', function () {
    $this->travelTo($this->fin->copy()->addMinute());
    $this->gateway()->declineNext();
    expect($this->renewNow($this->a, $this->sub->id)->value)->toBe('payment_failed');

    $this->travelTo(now()->addDay()->addMinute());
    $a = $this->a;
    $owner = $this->owner;
    $gateway = $this->gateway();
    $gateway->onCharge(function () use ($a, $owner, $gateway) {
        $gateway->onCharge(null);
        app(SubscriptionServiceInterface::class)->cancel($a, $owner, true);   // la baja llega mientras la pasarela reintenta
    });
    $this->renewNow($this->a, $this->sub->id);

    $this->ctx->run($this->a, function () {
        $renovacion = Invoice::query()->where('billing_reason', 'subscription_cycle')->firstOrFail();
        expect($renovacion->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->where('invoice_id', $renovacion->id)->where('status', 'succeeded')->count())->toBe(1)
            ->and(Subscription::query()->findOrFail($this->sub->id)->status)->toBe(SubscriptionStatus::Canceled);
    });
    expect($this->gateway()->calls('refund'))->toBe([]);
});

test('un ajuste de plan cobrado cuando la suscripción ya renovó se anula y se reembolsa en vez de quedarse el dinero', function () {
    $this->publishPlan('enterprise');
    $this->travelTo($this->fin->copy()->subDays(10));
    $subId = $this->sub->id;
    $gateway = $this->gateway();
    $gateway->onCharge(function () use ($gateway, $subId) {
        $gateway->onCharge(null);
        // Otro proceso renueva justo ahora: el periodo cambia mientras la pasarela cobra el ajuste.
        DB::table('subscriptions')->where('id', $subId)->update(['current_period_start' => $this->fin, 'current_period_end' => $this->fin->copy()->addMonth()]);
    });

    expect(fn () => $this->ctx->run($this->a, fn () => app(SubscriptionServiceInterface::class)->changePlan($this->a, $this->owner, 'enterprise', 'USD', BillingInterval::Month)))
        ->toThrow(rnCodigo('invoice_not_open', ['refund' => 'refunded']));

    $this->ctx->run($this->a, function () {
        $ajuste = Invoice::query()->where('billing_reason', 'subscription_update')->firstOrFail();
        expect($ajuste->status)->toBe(InvoiceStatus::Void)
            ->and(Payment::query()->where('invoice_id', $ajuste->id)->firstOrFail()->status->value)->toBe('refunded')
            ->and(Subscription::query()->findOrFail($this->sub->id)->plan_code)->toBe('pro')
            ->and(AuditLog::query()->where('event', 'billing.stale-upgrade-payment')->count())->toBe(1);
    });
    expect($this->gateway()->calls('refund'))->toHaveCount(1);
});

test('la máquina de estados también se aplica al guardar el modelo: una suscripción cancelada no vuelve a activa', function () {
    $this->ctx->run($this->a, fn () => app(SubscriptionServiceInterface::class)->cancel($this->a, $this->owner, true));

    $this->ctx->run($this->a, function () {
        $s = Subscription::query()->findOrFail($this->sub->id);
        $s->status = SubscriptionStatus::Active;

        expect(fn () => $s->save())->toThrow(rnCodigo('invalid_subscription_transition'))
            ->and(Subscription::query()->findOrFail($this->sub->id)->status)->toBe(SubscriptionStatus::Canceled);
    });
});
