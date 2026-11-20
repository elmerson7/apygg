<?php

use App\Contracts\PaymentGatewayInterface;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
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
    $this->platform = User::factory()->create();
    $this->platform->assignRole(Role::where('name', 'admin')->first());
});

/** Llamada del dueño de A (o del actor dado). */
function bl($test, string $method, string $uri, array $body = [], ?User $as = null, $tenant = null)
{
    return $test->isoCall($test->isoHeaders($as ?? $test->owner, $tenant ?? $test->a), $method, $uri, $body);
}

/** Una violación de restricción dentro de un SAVEPOINT: no aborta la transacción del test. */
function blFalla(Closure $fn): void
{
    expect(fn () => DB::transaction($fn))->toThrow(QueryException::class);
}

function blSinPrueba(): void
{
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
}

// ── Catálogo ─────────────────────────────────────────────────────────────────

test('GET /plans es público, lista los planes públicos con precios enteros y oculta los privados', function () {
    $res = $this->getJson('/plans')->assertOk();

    $codes = collect($res->json('data'))->pluck('code')->all();
    expect($codes)->toBe(['free', 'pro'])->and($codes)->not->toContain('enterprise');

    $pro = collect($res->json('data'))->firstWhere('code', 'pro');
    expect($pro['trial_days'])->toBe(14)
        ->and(collect($pro['prices'])->firstWhere(fn ($p) => $p['currency'] === 'USD' && $p['interval'] === 'month')['unit_amount_minor'])->toBe(2900)
        ->and($pro['limits']['max_members'])->toBe(25);
});

test('billing:sync-plans es idempotente y desactiva lo que sale de la config sin borrarlo', function () {
    Artisan::call('billing:sync-plans');
    $planes = Plan::query()->count();
    $precios = PlanPrice::query()->count();

    Artisan::call('billing:sync-plans');
    expect(Plan::query()->count())->toBe($planes)->and(PlanPrice::query()->count())->toBe($precios);

    config(['billing.plans.enterprise' => null]);
    $plans = config('billing.plans');
    unset($plans['enterprise']);
    config(['billing.plans' => $plans]);
    Artisan::call('billing:sync-plans');

    expect(Plan::query()->where('code', 'enterprise')->value('is_active'))->toBeFalse()
        ->and(Plan::query()->where('code', 'enterprise')->exists())->toBeTrue();
});

// ── Contratar ────────────────────────────────────────────────────────────────

test('contratar un plan con prueba no cobra, deja la suscripción en trialing y guarda el método de pago', function () {
    $this->travelTo('2026-11-20 12:00:00');

    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_visa'])->assertCreated();

    expect($res->json('data.status'))->toBe('trialing')
        ->and($res->json('data.unit_amount_minor'))->toBe(2900)
        ->and($res->json('data.currency'))->toBe('USD')
        ->and($res->json('data.trial_ends_at'))->toStartWith('2026-12-04T12:00:00');
    expect($this->gateway()->calls('charge'))->toBe([]);
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(0));

    bl($this, 'GET', '/tenant/billing/payment-method')->assertOk()->assertJsonPath('data.last4', '4242')->assertJsonMissingPath('data.payment_method_id');
});

test('la prueba se concede una sola vez por empresa', function () {
    $primera = $this->subscribeTo($this->a, $this->owner);
    expect($primera->status)->toBe(SubscriptionStatus::Trialing);

    bl($this, 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertOk();

    $segunda = $this->subscribeTo($this->a, $this->owner);
    expect($segunda->status)->toBe(SubscriptionStatus::Active)->and($segunda->trial_ends_at)->toBeNull();
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->where('status', 'paid')->count())->toBe(1));
});

test('contratar sin prueba cobra al momento: factura numerada, pago registrado con su clave de idempotencia y suscripción activa', function () {
    blSinPrueba();
    $this->travelTo('2026-11-20 12:00:00');

    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_visa'])->assertCreated();
    expect($res->json('data.status'))->toBe('active')
        ->and($res->json('data.current_period_end'))->toStartWith('2026-12-20T12:00:00');

    $this->ctx->run($this->a, function () {
        $invoice = Invoice::query()->with('lines')->sole();
        $payment = Payment::query()->sole();

        expect($invoice->number)->toBe('F-2026-000001')
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->subtotal->amountMinor)->toBe(2900)
            ->and($invoice->tax->amountMinor)->toBe(0)
            ->and($invoice->total->amountMinor)->toBe(2900)
            ->and($invoice->currency)->toBe('USD')
            ->and($invoice->lines)->toHaveCount(1)
            ->and($payment->idempotency_key)->toBe("inv-{$invoice->id}-a1")
            ->and($payment->status->value)->toBe('succeeded')
            ->and($payment->amount->amountMinor)->toBe(2900);
    });

    $cargos = $this->gateway()->charges();
    expect($cargos)->toHaveCount(1)->and($cargos[0]->amount->amountMinor)->toBe(2900);
});

test('el impuesto simple se calcula una vez sobre el subtotal y total = subtotal + impuesto', function () {
    blSinPrueba();
    config(['billing.tax.rate' => '18', 'billing.tax.label' => 'IGV']);

    $this->subscribeTo($this->a, $this->owner);

    $this->ctx->run($this->a, function () {
        $invoice = Invoice::query()->with('lines')->sole();
        expect($invoice->subtotal->amountMinor)->toBe(2900)
            ->and($invoice->tax->amountMinor)->toBe(522)
            ->and($invoice->total->amountMinor)->toBe(3422)
            ->and($invoice->tax_label)->toBe('IGV')
            ->and($invoice->lines->sum(fn ($l) => $l->amount->amountMinor))->toBe($invoice->subtotal->amountMinor);
    });
});

test('un cobro rechazado en el alta deja la suscripción incomplete y la factura abierta, y se puede pagar después con otra tarjeta', function () {
    blSinPrueba();

    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_declined'])->assertStatus(402);
    expect($res->json('code'))->toBe('payment_failed')->and($res->json('failure_code'))->toBe('card_declined');

    $this->ctx->run($this->a, function () {
        expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Incomplete)
            ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Open)
            ->and(Payment::query()->sole()->status->value)->toBe('failed');
    });

    bl($this, 'PUT', '/tenant/billing/payment-method', ['token' => 'tok_visa'])->assertOk();
    $invoiceId = $this->ctx->run($this->a, fn () => Invoice::query()->value('id'));

    bl($this, 'POST', "/tenant/billing/invoices/{$invoiceId}/pay")->assertOk()->assertJsonPath('data.status', 'paid');

    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)->and(Payment::query()->count())->toBe(2));
});

test('un plan de pago sin prueba y sin método de pago se rechaza y no crea nada', function () {
    blSinPrueba();

    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month'])->assertStatus(422);
    expect($res->json('code'))->toBe('payment_method_required');
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->count())->toBe(0)->and(Invoice::query()->count())->toBe(0));
});

test('un plan gratuito se activa sin factura ni cobro', function () {
    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'free', 'interval' => 'month'])->assertCreated();

    expect($res->json('data.status'))->toBe('active')->and($res->json('data.unit_amount_minor'))->toBe(0);
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(0));
});

test('un plan privado no lo contrata el tenant aunque conozca el código (403 plan_private); un plan o moneda inexistentes dan error claro', function () {
    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'enterprise', 'interval' => 'month', 'payment_token' => 'tok_visa'])->assertForbidden();
    expect($res->json('code'))->toBe('plan_private');
    expect(fn () => $this->subscribeTo($this->a, $this->owner, 'enterprise'))->toThrow(fn (BillingException $e) => expect($e->errorCode())->toBe('plan_private'));
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->count())->toBe(0));
    expect($this->gateway()->calls())->toBe([]);   // ni cliente, ni tarjeta, ni cobro

    // Con una suscripción pública viva tampoco puede cambiarse al privado (ni simularlo).
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);
    bl($this, 'POST', '/tenant/billing/subscription/preview-change', ['plan' => 'enterprise', 'interval' => 'month'])->assertForbidden()->assertJsonPath('code', 'plan_private');
    bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertForbidden()->assertJsonPath('code', 'plan_private');
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->plan_code)->toBe('pro'));

    bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'nope', 'interval' => 'month'], $this->owner, $this->b)->assertStatus(403);   // dueño de A no es miembro de B
    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'nope', 'interval' => 'month'])->assertNotFound();
    expect($res->json('code'))->toBe('plan_not_found');

    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'currency' => 'JPY'])->assertStatus(422);
    expect($res->json('code'))->toBe('price_unavailable');
});

test('solo hay una suscripción viva por empresa: el servicio responde 409 y la BD lo impide aunque se salte el servicio', function () {
    $this->subscribeTo($this->a, $this->owner);

    $res = bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'free', 'interval' => 'month'])->assertStatus(409);
    expect($res->json('code'))->toBe('subscription_exists');

    $dup = $this->ctx->run($this->a, fn () => Subscription::query()->sole()->replicate()->getAttributes());
    blFalla(fn () => DB::table('subscriptions')->insert($dup + ['tenant_id' => $this->a->id, 'created_at' => now(), 'updated_at' => now()]));
});

// ── Permisos ─────────────────────────────────────────────────────────────────

test('un miembro raso no gestiona ni ve la facturación (403) y el dueño y el admin sí', function () {
    bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'free', 'interval' => 'month'], $this->member)->assertForbidden();
    bl($this, 'GET', '/tenant/billing/subscription', [], $this->member)->assertForbidden();
    bl($this, 'GET', '/tenant/billing/invoices', [], $this->member)->assertForbidden();
    bl($this, 'PUT', '/tenant/billing/payment-method', ['token' => 'tok_visa'], $this->member)->assertForbidden();

    bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'free', 'interval' => 'month'], $this->admin)->assertCreated();
    bl($this, 'GET', '/tenant/billing/subscription', [], $this->owner)->assertOk()->assertJsonPath('data.plan_code', 'free');
});

test('el rol del actor en ESTE tenant manda: un dueño en A que es miembro raso en B no gestiona B', function () {
    $this->b->users()->attach($this->owner->getKey(), ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);

    bl($this, 'POST', '/tenant/billing/subscription', ['plan' => 'free', 'interval' => 'month'], $this->owner, $this->b)->assertStatus(403)
        ->assertJsonPath('code', 'tenant_role_insufficient');
    $this->ctx->run($this->b, fn () => expect(Subscription::query()->count())->toBe(0));
});

test('las rutas de plataforma exigen billing.manage: ni el dueño del tenant ni un admin de tenant', function () {
    bl($this, 'POST', '/admin/billing/tenants/'.$this->a->id.'/free-period', ['days' => 5, 'reason' => 'x'], $this->owner)->assertForbidden();
    bl($this, 'POST', '/admin/billing/invoices/1/mark-paid', ['reference' => 'r', 'reason' => 'x'], $this->admin)->assertForbidden();
    $this->asHeaders([])->getJson('/tenant/billing/subscription')->assertUnauthorized();
});

// ── Baja y reanudación ───────────────────────────────────────────────────────

test('cancelar al final del periodo es idempotente, no cambia el estado y se puede reanudar', function () {
    $this->subscribeTo($this->a, $this->owner);

    bl($this, 'POST', '/tenant/billing/subscription/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.status', 'trialing');
    $cuando = $this->ctx->run($this->a, fn () => Subscription::query()->sole()->canceled_at);
    $this->travel(5)->minutes();
    bl($this, 'POST', '/tenant/billing/subscription/cancel')->assertOk();
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->sole()->canceled_at->equalTo($cuando))->toBeTrue());

    bl($this, 'POST', '/tenant/billing/subscription/resume')->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
    $res = bl($this, 'POST', '/tenant/billing/subscription/resume')->assertStatus(409);
    expect($res->json('code'))->toBe('subscription_not_resumable');
});

test('cancelar de inmediato termina la suscripción, anula la factura del alta y no se puede reanudar', function () {
    blSinPrueba();
    $this->gateway()->declineNext();
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(BillingException::class);

    bl($this, 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertOk()->assertJsonPath('data.status', 'canceled');

    $this->ctx->run($this->a, fn () => expect(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Void)->and(Subscription::query()->sole()->ended_at)->not->toBeNull());
    bl($this, 'POST', '/tenant/billing/subscription/resume')->assertNotFound()->assertJsonPath('code', 'subscription_not_found');
});

// ── Cambio de plan ───────────────────────────────────────────────────────────

test('una subida de plan es inmediata: prorrateo en enteros, factura de ajuste cobrada y plan nuevo con el periodo intacto', function () {
    $this->publishPlan('enterprise');
    blSinPrueba();
    $this->travelTo('2026-11-20 12:00:00');
    $this->subscribeTo($this->a, $this->owner);                       // periodo 20/11 - 20/12 (30 días)
    $this->travelTo('2026-12-05 12:00:00');                           // quedan 15 de 30: mitad exacta

    $preview = bl($this, 'POST', '/tenant/billing/subscription/preview-change', ['plan' => 'enterprise', 'interval' => 'month'])->assertOk();
    expect($preview->json('data.mode'))->toBe('immediate')
        ->and($preview->json('data.proration.net.amount_minor'))->toBe(48500);

    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertOk();
    expect($res->json('data.plan_code'))->toBe('enterprise')
        ->and($res->json('data.unit_amount_minor'))->toBe(99900)
        ->and($res->json('data.current_period_end'))->toStartWith('2026-12-20T12:00:00');

    $this->ctx->run($this->a, function () {
        $ajuste = Invoice::query()->with('lines')->where('billing_reason', 'subscription_update')->sole();
        expect($ajuste->status)->toBe(InvoiceStatus::Paid)
            ->and($ajuste->total->amountMinor)->toBe(48500)
            ->and($ajuste->lines->pluck('amount.amountMinor')->all())->toBe([-1450, 49950])
            ->and($ajuste->lines->sum(fn ($l) => $l->amount->amountMinor))->toBe($ajuste->subtotal->amountMinor);
    });
});

test('una subida cuyo cobro es rechazado no cambia el plan, anula el ajuste y no cuenta como morosidad', function () {
    $this->publishPlan('enterprise');
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);
    $this->travel(10)->days();
    $this->gateway()->declineNext('insufficient_funds');

    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertStatus(402);
    expect($res->json('failure_code'))->toBe('insufficient_funds');

    $this->ctx->run($this->a, function () {
        $s = Subscription::query()->sole();
        expect($s->plan_code)->toBe('pro')->and($s->status)->toBe(SubscriptionStatus::Active)->and($s->renewal_attempts)->toBe(0)
            ->and(Invoice::query()->where('billing_reason', 'subscription_update')->sole()->status)->toBe(InvoiceStatus::Void);
    });
});

test('una bajada de plan se programa para el final del periodo sin cobrar nada y se puede deshacer', function () {
    $this->publishPlan('enterprise');
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner, 'enterprise');

    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'pro', 'interval' => 'month'])->assertOk();
    expect($res->json('data.plan_code'))->toBe('enterprise')->and($res->json('data.scheduled_plan_price_id'))->not->toBeNull();
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(1));

    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertOk();
    expect($res->json('data.scheduled_plan_price_id'))->toBeNull();
});

test('cambiar al mismo plan y precio es un error y en prueba el cambio no cobra', function () {
    $this->publishPlan('enterprise');
    $this->subscribeTo($this->a, $this->owner, 'free');
    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'free', 'interval' => 'month'])->assertStatus(422);
    expect($res->json('code'))->toBe('same_plan');

    $this->ctx->run($this->a, fn () => Subscription::query()->delete());
    $this->subscribeTo($this->a, $this->owner);   // pro en prueba
    bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertOk()->assertJsonPath('data.plan_code', 'enterprise');
    expect($this->gateway()->calls('charge'))->toBe([]);
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->count())->toBe(0));
});

test('no se puede cambiar de plan con la suscripción en past_due', function () {
    $this->publishPlan('enterprise');
    blSinPrueba();
    $s = $this->subscribeTo($this->a, $this->owner);
    $this->ctx->run($this->a, fn () => Subscription::query()->whereKey($s->id)->update(['status' => 'past_due']));

    $res = bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertStatus(409);
    expect($res->json('code'))->toBe('subscription_not_active');
});

test('la plataforma asigna un plan privado con motivo: lo contrata, lo cobra con el método del tenant y lo audita; el tenant no usa esa ruta', function () {
    bl($this, 'PUT', '/tenant/billing/payment-method', ['token' => 'tok_visa'])->assertOk();
    $uri = '/admin/billing/tenants/'.$this->a->id.'/plan';

    bl($this, 'POST', $uri, ['plan' => 'enterprise', 'interval' => 'month', 'reason' => 'x'], $this->owner)->assertForbidden();   // dueño del tenant: no es plataforma
    bl($this, 'POST', $uri, ['plan' => 'enterprise', 'interval' => 'month'], $this->platform)->assertStatus(422);                 // sin motivo
    expect($this->gateway()->charges())->toBe([]);

    $res = bl($this, 'POST', $uri, ['plan' => 'enterprise', 'interval' => 'month', 'reason' => 'Acuerdo firmado'], $this->platform)->assertOk();
    expect($res->json('data.plan_code'))->toBe('enterprise')->and($res->json('data.status'))->toBe('active')
        ->and($this->gateway()->charges())->toHaveCount(1)
        ->and($this->gateway()->charges()[0]->amount->amountMinor)->toBe(99900);

    $entry = AuditLog::query()->where('event', 'billing.plan-assigned')->sole();
    expect($entry->reason)->toBe('Acuerdo firmado')->and($entry->tenant_id)->toBe($this->a->id)
        ->and($entry->new_values['mode'])->toBe('subscribe')->and($entry->new_values['plan'])->toBe('enterprise')
        ->and($entry->new_values['actor_id'])->toBe($this->platform->id);

    // Con suscripción viva, la misma ruta cambia el plan (aquí una bajada, programada al final del periodo).
    bl($this, 'POST', $uri, ['plan' => 'pro', 'interval' => 'month', 'reason' => 'Vuelve a pro'], $this->platform)->assertOk()->assertJsonPath('data.plan_code', 'enterprise');
    expect(AuditLog::query()->where('event', 'billing.plan-assigned')->count())->toBe(2);

    // El tenant ya está en ese precio privado: volver a él (deshacer la bajada) sí le está permitido.
    bl($this, 'POST', '/tenant/billing/subscription/change', ['plan' => 'enterprise', 'interval' => 'month'])->assertOk()->assertJsonPath('data.scheduled_plan_price_id', null);

    bl($this, 'POST', '/admin/billing/tenants/999999/plan', ['plan' => 'pro', 'interval' => 'month', 'reason' => 'x'], $this->platform)->assertNotFound();
});

test('el PDF de una factura se descarga con permiso de lectura; el miembro raso no puede', function () {
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);
    $id = $this->ctx->run($this->a, fn () => Invoice::query()->value('id'));

    $res = bl($this, 'GET', "/tenant/billing/invoices/{$id}/pdf")->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($res->headers->get('Content-Disposition'))->toContain('attachment')->toContain('factura-F-2026-')
        ->and($res->streamedContent())->toStartWith('%PDF');

    bl($this, 'GET', "/tenant/billing/invoices/{$id}/pdf", [], $this->admin)->assertOk();
    bl($this, 'GET', "/tenant/billing/invoices/{$id}/pdf", [], $this->member)->assertForbidden();
    bl($this, 'GET', '/tenant/billing/invoices/999999/pdf')->assertNotFound();
});

// ── Periodo gratis y pago manual ─────────────────────────────────────────────

test('un admin de plataforma concede días gratis con motivo: el periodo se alarga y queda auditado', function () {
    $s = $this->subscribeTo($this->a, $this->owner);
    $antes = $s->current_period_end;

    $res = bl($this, 'POST', '/admin/billing/tenants/'.$this->a->id.'/free-period', ['days' => 10, 'reason' => 'Compensación por caída'], $this->platform)->assertOk();
    expect($res->json('data.current_period_end'))->toBe($antes->copy()->addDays(10)->toIso8601String());

    $entry = AuditLog::query()->where('event', 'billing.free-period')->sole();
    expect($entry->reason)->toBe('Compensación por caída')->and($entry->tenant_id)->toBe($this->a->id)->and($entry->new_values['days'])->toBe(10);
});

test('el periodo gratis exige motivo, un número de días razonable y una suscripción en prueba o activa', function () {
    $this->subscribeTo($this->a, $this->owner);
    $uri = '/admin/billing/tenants/'.$this->a->id.'/free-period';

    bl($this, 'POST', $uri, ['days' => 10], $this->platform)->assertStatus(422);
    bl($this, 'POST', $uri, ['days' => 0, 'reason' => 'x'], $this->platform)->assertStatus(422);
    bl($this, 'POST', $uri, ['days' => 9999, 'reason' => 'x'], $this->platform)->assertStatus(422);
    bl($this, 'POST', '/admin/billing/tenants/'.$this->b->id.'/free-period', ['days' => 3, 'reason' => 'x'], $this->platform)->assertNotFound();
});

test('el pago manual marca la factura pagada con referencia y motivo, activa la suscripción y queda auditado', function () {
    blSinPrueba();
    $this->gateway()->declineNext();
    expect(fn () => $this->subscribeTo($this->a, $this->owner))->toThrow(BillingException::class);
    $invoiceId = $this->ctx->run($this->a, fn () => Invoice::query()->value('id'));

    bl($this, 'POST', "/admin/billing/invoices/{$invoiceId}/mark-paid", ['reference' => 'TRF-001'], $this->platform)->assertStatus(422);   // sin motivo

    $res = bl($this, 'POST', "/admin/billing/invoices/{$invoiceId}/mark-paid", ['reference' => 'TRF-001', 'reason' => 'Transferencia verificada'], $this->platform)->assertOk();
    expect($res->json('data.status'))->toBe('paid');

    $this->ctx->run($this->a, function () {
        $manual = Payment::query()->where('method', 'manual')->sole();
        expect($manual->reference)->toBe('TRF-001')->and($manual->reason)->toBe('Transferencia verificada')->and($manual->recorded_by)->toBe($this->platform->id)
            ->and($manual->tenant_id)->toBe($this->a->id)
            ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active);
    });

    $entry = AuditLog::query()->where('event', 'billing.manual-payment')->sole();
    expect($entry->reason)->toBe('Transferencia verificada')->and($entry->new_values['reference'])->toBe('TRF-001')->and($entry->tenant_id)->toBe($this->a->id);

    $again = bl($this, 'POST', "/admin/billing/invoices/{$invoiceId}/mark-paid", ['reference' => 'TRF-002', 'reason' => 'otra vez'], $this->platform)->assertStatus(409);
    expect($again->json('code'))->toBe('invoice_not_open');
    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('status', 'succeeded')->count())->toBe(1));
    bl($this, 'POST', '/admin/billing/invoices/999999/mark-paid', ['reference' => 'r', 'reason' => 'x'], $this->platform)->assertNotFound();
});

// ── Facturas inmutables y consulta ───────────────────────────────────────────

test('una factura emitida es inmutable: ni sus importes, ni su número, ni sus líneas, ni se borra; solo cambia de estado', function () {
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);

    $this->ctx->run($this->a, function () {
        $invoice = Invoice::query()->sole();

        foreach ([['total_minor', 1], ['number', 'X-1'], ['currency', 'EUR'], ['tax_rate', '99']] as [$col, $val]) {
            $i = Invoice::query()->findOrFail($invoice->id);
            $i->{$col} = $val;
            expect(fn () => $i->save())->toThrow(BillingException::class);
        }

        expect(fn () => $invoice->delete())->toThrow(BillingException::class)
            ->and(fn () => InvoiceLine::query()->first()->update(['description' => 'x']))->toThrow(BillingException::class)
            ->and(fn () => InvoiceLine::query()->first()->delete())->toThrow(BillingException::class);

        $invoice->status = InvoiceStatus::Open;   // paid -> open: transición prohibida
        expect(fn () => $invoice->save())->toThrow(BillingException::class);
    });
});

test('la BD impide una factura incoherente, dos del mismo periodo y dos cobros exitosos de una factura', function () {
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);

    $this->ctx->run($this->a, function () {
        $inv = (array) DB::table('invoices')->first();
        $fila = fn (array $over) => array_merge(Arr::except($inv, ['id']), ['created_at' => now(), 'updated_at' => now()], $over);

        blFalla(fn () => DB::table('invoices')->insert($fila(['number' => 'Z-1', 'total_minor' => 1, 'billing_reason' => 'subscription_update'])));
        blFalla(fn () => DB::table('invoices')->insert($fila(['number' => 'Z-2'])));   // mismo (subscription_id, period_start)

        $pay = (array) DB::table('payments')->first();
        blFalla(fn () => DB::table('payments')->insert(Arr::except($pay, ['id']) + ['idempotency_key' => 'otra-clave', 'attempt' => 2]));   // segundo cobro exitoso de la misma factura
    });
});

test('el listado de facturas es paginado, filtrable y solo del tenant; el detalle trae líneas e intentos', function () {
    blSinPrueba();
    $this->subscribeTo($this->a, $this->owner);

    $res = bl($this, 'GET', '/tenant/billing/invoices?status=paid&per_page=5')->assertOk();
    expect($res->json('meta.total'))->toBe(1)->and($res->json('data.0.number'))->toBe('F-'.now()->format('Y').'-000001');
    bl($this, 'GET', '/tenant/billing/invoices?status=open')->assertOk()->assertJsonCount(0, 'data');
    bl($this, 'GET', '/tenant/billing/invoices?status=bogus')->assertStatus(422);

    $id = $res->json('data.0.id');
    $detalle = bl($this, 'GET', "/tenant/billing/invoices/{$id}")->assertOk();
    expect($detalle->json('data.lines'))->toHaveCount(1)->and($detalle->json('data.payments.0.status'))->toBe('succeeded')
        ->and($detalle->json('data.total_minor'))->toBe(2900)->and($detalle->json('data'))->not->toHaveKey('gateway_payment_id');
});

test('con PAYMENTS_MODE=null no hay cobro automático: el cobro cae a moroso y se recupera con pago manual', function () {
    blSinPrueba();
    $s = $this->subscribeTo($this->a, $this->owner);   // con la pasarela falsa
    config(['integrations.payments.mode' => 'null']);
    app()->forgetInstance(PaymentGatewayInterface::class);

    $this->travelTo($s->current_period_end->copy()->addMinute());
    expect($this->renewNow($this->a, $s->id)->value)->toBe('payment_failed');

    $this->ctx->run($this->a, function () use ($s) {
        expect(Subscription::query()->findOrFail($s->id)->status)->toBe(SubscriptionStatus::PastDue)
            ->and(Payment::query()->orderByDesc('id')->first()->failure_code)->toBe('no_payment_method');
    });

    $abierta = $this->ctx->run($this->a, fn () => Invoice::query()->where('status', 'open')->value('id'));
    bl($this, 'POST', "/admin/billing/invoices/{$abierta}/mark-paid", ['reference' => 'CHQ-9', 'reason' => 'Cheque recibido'], $this->platform)->assertOk();
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->findOrFail($s->id)->status)->toBe(SubscriptionStatus::Active));
});
