<?php

use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Enums\InvoiceReason;
use App\Exceptions\BillingException;
use App\Exceptions\PlanLimitReachedException;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Payments\ChargeResult;
use App\Jobs\ProcessInboundWebhookJob;
use App\Models\InboundWebhookReceipt;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\LineSpec;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales (procesos PHP a la vez) del DINERO, ola 6.3-A. Cada prueba dice qué carrera cubre y con cuántos procesos:
 *
 *  1. 2 renovaciones + 1 «pagar ahora» del cliente sobre una factura abierta (3 procesos): un solo cobro.
 *  2. 2 renovaciones + 1 pago manual de la plataforma (3 procesos): un solo pago, y si fue manual no hubo cobro.
 *  3. Mismo evento de Stripe recibido 4 veces a la vez (4) y mismo recibo procesado por 4 workers (4): un solo efecto.
 *  4. Webhook vs respuesta síncrona del mismo PaymentIntent (1 renovación + 2 webhooks): un cobro, un avance de periodo.
 *  5. Webhook de OTRO PaymentIntent vs respuesta síncrona (1 + 2): un solo ganador y el perdedor queda como doble cobro.
 *  6. Cancelación inmediata vs renovación en 3 empresas (6): nunca un cobro vivo sobre una factura anulada.
 *  7. Cancelación al fin del periodo vs renovación en 2 empresas (4): o se cobra y sigue, o se cancela sin cobro.
 *  8. Numeración por empresa (alcance `tenant`) con 3 empresas x 2 emisiones simultáneas (6): 1..3 por empresa, sin huecos.
 *  9. Límite de plan (API keys) con 6 altas simultáneas y tope 2 (6): exactamente 2 caben.
 *
 * Los datos quedan comprometidos: se limpian a mano. La pasarela falsa vive en la memoria de CADA proceso (sus llamadas se suman).
 */

beforeEach(function () {
    $this->sufijo = bin2hex(random_bytes(3));
    config(['multi-tenancy.enabled' => true, 'queue.default' => 'sync', 'billing.invoice.sequence_scope' => 'global']);
    $this->seed(RoleSeeder::class);
    app(PlanCatalogInterface::class)->sync();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    DB::table('sequences')->where('name', 'billing_invoice')->whereNull('tenant_id')->delete();
});

afterEach(function () {
    $ids = DB::table('tenants')->where('slug', 'like', 'carrera-cobro-%')->pluck('id');
    DB::table('payment_discrepancies')->whereIn('tenant_id', $ids)->delete();
    DB::table('inbound_webhook_receipts')->whereIn('tenant_id', $ids)->delete();
    DB::table('payments')->whereIn('tenant_id', $ids)->delete();
    DB::table('invoice_lines')->whereIn('invoice_id', DB::table('invoices')->whereIn('tenant_id', $ids)->select('id'))->delete();
    DB::table('invoices')->whereIn('tenant_id', $ids)->delete();
    DB::table('subscriptions')->whereIn('tenant_id', $ids)->delete();
    DB::table('billing_accounts')->whereIn('tenant_id', $ids)->delete();
    DB::table('api_keys')->whereIn('tenant_id', $ids)->delete();
    DB::table('sequences')->whereIn('tenant_id', $ids)->delete();
    DB::table('audit_logs')->whereIn('tenant_id', $ids)->delete();
    DB::table('tenants')->whereIn('id', $ids)->delete();
    DB::table('users')->where('email', 'like', 'carrera-cobro-%@example.com')->delete();
    DB::table('sequences')->where('name', 'billing_invoice')->whereNull('tenant_id')->delete();
    DB::table('plan_prices')->delete();
    DB::table('plans')->delete();
});

/** Empresa con dueño y suscripción `pro` activa ya VENCIDA. @return array{tenant: Tenant, owner: User, sub: Subscription} */
function ccxEmpresa(string $sufijo, string $n, bool $vencida = true): array
{
    $tenant = Tenant::create(['name' => "Cobro {$n}", 'slug' => "carrera-cobro-{$sufijo}-{$n}", 'status' => 'active']);
    $owner = User::factory()->create(['email' => "carrera-cobro-{$sufijo}-{$n}@example.com"]);
    $tenant->users()->attach($owner->getKey(), ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    TenantRoles::sync($owner, $tenant, 'owner');

    $sub = app(TenantContext::class)->run($tenant, fn () => app(SubscriptionServiceInterface::class)->subscribe($tenant, $owner, 'pro', 'USD', BillingInterval::Month, 'tok_visa'));

    if ($vencida) {
        DB::table('subscriptions')->where('id', $sub->id)->update(['current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);
    }

    return ['tenant' => $tenant, 'owner' => $owner, 'sub' => app(TenantContext::class)->run($tenant, fn () => $sub->refresh())];
}

/** Una renovación que la pasarela rechazó: factura de ciclo abierta, suscripción `past_due` con el reintento ya vencido. */
function ccxFacturaAbierta(array $e): int
{
    $sid = $e['sub']->id;
    app(TenantContext::class)->run($e['tenant'], function () use ($sid) {
        app(PaymentGatewayInterface::class)->declineNext();
        app(RenewalServiceInterface::class)->renew($sid);
    });
    DB::table('subscriptions')->where('id', $sid)->update(['next_retry_at' => now()->subMinute()]);

    $factura = (int) DB::table('invoices')->where('subscription_id', $sid)->where('status', 'open')->value('id');
    expect($factura)->toBeGreaterThan(0);

    return $factura;
}

/** Una renovación vencida con el cobro `pending` (respuesta perdida) y su arrendamiento vencido, lista para retomar. */
function ccxCobroPendiente(array $e): object
{
    $sid = $e['sub']->id;
    app(PaymentGatewayInterface::class)->failNext(TransientIntegrationError::class);
    expect(app(TenantContext::class)->run($e['tenant'], fn () => app(RenewalServiceInterface::class)->renew($sid))->value)->toBe('retrying');
    $pago = DB::table('payments')->where('tenant_id', $e['tenant']->id)->where('status', 'pending')->first();
    DB::table('payments')->where('id', $pago->id)->update(['claimed_until' => now()->subMinute()]);

    return $pago;
}

test('dos renovaciones y un «pagar ahora» del cliente a la vez sobre la misma factura abierta cobran UNA sola vez (3 procesos)', function () {
    $e = ccxEmpresa($this->sufijo, 'a');
    $factura = ccxFacturaAbierta($e);
    [$tid, $uid, $sid] = [$e['tenant']->id, $e['owner']->id, $e['sub']->id];

    $results = ConcurrentRunner::run(3, function (int $i) use ($tid, $uid, $sid, $factura) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);

        try {
            $res = $i < 2
                ? app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value
                : (function () use ($tenant, $uid, $factura) {
                    app(TenantContext::class)->run($tenant, fn () => app(PaymentProcessorInterface::class)->pay($tenant, User::query()->findOrFail($uid), $factura));

                    return 'ok';
                })();
        } catch (BillingException $ex) {
            $res = $ex->errorCode();
        }

        return ['res' => $res, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->sum('result.charges'))->toBe(1, json_encode($results))
        ->and(DB::table('payments')->where('invoice_id', $factura)->where('status', 'succeeded')->count())->toBe(1)
        ->and(DB::table('invoices')->where('id', $factura)->value('status'))->toBe('paid')
        ->and(DB::table('subscriptions')->where('id', $sid)->value('status'))->toBe('active');
});

test('dos renovaciones y un pago manual de la plataforma a la vez: un solo pago saldado, y si fue manual no hubo cobro por la pasarela (3 procesos)', function () {
    $e = ccxEmpresa($this->sufijo, 'm');
    $factura = ccxFacturaAbierta($e);
    [$tid, $uid, $sid] = [$e['tenant']->id, $e['owner']->id, $e['sub']->id];

    $results = ConcurrentRunner::run(3, function (int $i) use ($tid, $uid, $sid, $factura) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);

        try {
            $res = $i < 2
                ? app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value
                : (app(InvoiceServiceInterface::class)->markPaidManually($factura, User::query()->findOrFail($uid), 'transferencia-1', 'Pago recibido por transferencia') ? 'ok' : 'no');
        } catch (BillingException $ex) {
            $res = $ex->errorCode();
        }

        return ['res' => $res, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $manual = DB::table('payments')->where('invoice_id', $factura)->where('status', 'succeeded')->where('method', 'manual')->count();
    expect(DB::table('payments')->where('invoice_id', $factura)->where('status', 'succeeded')->count())->toBe(1, json_encode($results))
        ->and(DB::table('invoices')->where('id', $factura)->value('status'))->toBe('paid')
        ->and(collect($results)->sum('result.charges'))->toBe($manual === 1 ? 0 : 1, json_encode($results));
});

test('el mismo evento de Stripe recibido cuatro veces a la vez deja UN recibo, y el mismo recibo procesado por cuatro workers tiene un solo efecto (4 + 4 procesos)', function () {
    $e = ccxEmpresa($this->sufijo, 'w');
    $pago = ccxCobroPendiente($e);
    [$tid, $sid] = [$e['tenant']->id, $e['sub']->id];
    $finAnterior = $e['sub']->current_period_end->toDateTimeString();
    $cliente = (string) DB::table('billing_accounts')->where('tenant_id', $tid)->value('gateway_customer_id');
    $delivery = 'evt_carrera_'.$this->sufijo;
    $payload = ['id' => $delivery, 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'object' => 'payment_intent', 'id' => 'pi_carrera_'.$this->sufijo, 'status' => 'succeeded', 'amount' => (int) $pago->amount_minor, 'currency' => strtolower((string) $pago->currency),
        'customer' => $cliente, 'metadata' => ['idempotency_key' => $pago->idempotency_key],
    ]]];

    // 1) Cuatro entregas simultáneas del mismo evento: gana una sola inserción.
    $recibos = ConcurrentRunner::run(4, function (int $i) use ($tid, $delivery, $payload) {
        $r = InboundWebhookReceipt::recordOnce(['provider' => 'stripe', 'delivery_id' => $delivery, 'tenant_id' => $tid, 'payload' => $payload]);

        return $r?->id;
    }, 90);

    foreach ($recibos as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($recibos)->pluck('result')->filter()->count())->toBe(1, json_encode($recibos))
        ->and(DB::table('inbound_webhook_receipts')->where('delivery_id', $delivery)->count())->toBe(1);
    $receiptId = (int) DB::table('inbound_webhook_receipts')->where('delivery_id', $delivery)->value('id');

    // 2) Cuatro workers con el mismo recibo: el efecto ocurre una sola vez (el handler es idempotente y bloquea).
    $workers = ConcurrentRunner::run(4, function (int $i) use ($receiptId) {
        config(['multi-tenancy.enabled' => true]);
        ProcessInboundWebhookJob::dispatchSync($receiptId);

        return 'ok';
    }, 90);

    foreach ($workers as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(DB::table('inbound_webhook_receipts')->where('id', $receiptId)->value('status'))->toBe('processed')
        ->and(DB::table('payments')->where('id', $pago->id)->value('status'))->toBe('succeeded')
        ->and(DB::table('payments')->where('id', $pago->id)->value('gateway_payment_id'))->toBe('pi_carrera_'.$this->sufijo)
        ->and(DB::table('payments')->where('invoice_id', $pago->invoice_id)->count())->toBe(1)
        ->and(DB::table('invoices')->where('id', $pago->invoice_id)->value('status'))->toBe('paid')
        ->and(DB::table('subscriptions')->where('id', $sid)->value('current_period_start'))->toBe($finAnterior)
        ->and(DB::table('payment_discrepancies')->where('tenant_id', $tid)->count())->toBe(0);
});

test('el webhook y la respuesta síncrona del MISMO PaymentIntent a la vez saldan la factura una vez, sin discrepancias (1 renovación + 2 webhooks)', function () {
    $e = ccxEmpresa($this->sufijo, 's');
    $pago = ccxCobroPendiente($e);
    [$tid, $sid] = [$e['tenant']->id, $e['sub']->id];
    $finAnterior = $e['sub']->current_period_end->toDateTimeString();
    $cliente = (string) DB::table('billing_accounts')->where('tenant_id', $tid)->value('gateway_customer_id');

    $results = ConcurrentRunner::run(3, function (int $i) use ($tid, $sid, $pago, $cliente) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);

        if ($i === 0) {
            // En un proceso nuevo la pasarela falsa numera su primer cobro `fake_pay_1`: es el id que dirán los webhooks.
            $out = app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value;

            return ['kind' => 'sync', 'res' => $out, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        }

        $charge = new ChargeResult(true, 'fake_pay_1', amountMinor: (int) $pago->amount_minor, currency: (string) $pago->currency, customerId: $cliente, idempotencyKey: $pago->idempotency_key);

        return ['kind' => 'webhook', 'res' => app(TenantContext::class)->run($tenant, fn () => app(PaymentReconcilerInterface::class)->applyCharge($charge)), 'charges' => 0];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(DB::table('payments')->where('id', $pago->id)->value('status'))->toBe('succeeded')
        ->and(DB::table('payments')->where('id', $pago->id)->value('gateway_payment_id'))->toBe('fake_pay_1')
        ->and(DB::table('payments')->where('invoice_id', $pago->invoice_id)->count())->toBe(1)
        ->and(DB::table('invoices')->where('id', $pago->invoice_id)->value('status'))->toBe('paid')
        ->and(DB::table('subscriptions')->where('id', $sid)->value('current_period_start'))->toBe($finAnterior)
        ->and(DB::table('payment_discrepancies')->where('tenant_id', $tid)->count())->toBe(0, json_encode($results));
});

test('el webhook de OTRO PaymentIntent y la respuesta síncrona a la vez: un solo ganador y el perdedor queda como doble cobro a devolver (1 renovación + 2 webhooks)', function () {
    $e = ccxEmpresa($this->sufijo, 'o');
    $pago = ccxCobroPendiente($e);
    [$tid, $sid] = [$e['tenant']->id, $e['sub']->id];
    $finAnterior = $e['sub']->current_period_end->toDateTimeString();
    $cliente = (string) DB::table('billing_accounts')->where('tenant_id', $tid)->value('gateway_customer_id');

    $results = ConcurrentRunner::run(3, function (int $i) use ($tid, $sid, $pago, $cliente) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);

        if ($i === 0) {
            $out = app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value;

            return ['kind' => 'sync', 'res' => $out, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        }

        $charge = new ChargeResult(true, 'pi_otro', amountMinor: (int) $pago->amount_minor, currency: (string) $pago->currency, customerId: $cliente, idempotencyKey: $pago->idempotency_key);

        return ['kind' => 'webhook', 'res' => app(TenantContext::class)->run($tenant, fn () => app(PaymentReconcilerInterface::class)->applyCharge($charge)), 'charges' => 0];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $ganador = (string) DB::table('payments')->where('id', $pago->id)->value('gateway_payment_id');
    $cobrados = ['pi_otro'] + (collect($results)->sum('result.charges') === 1 ? [1 => 'fake_pay_1'] : []);
    $perdedores = array_values(array_diff($cobrados, [$ganador]));
    $registrados = DB::table('payment_discrepancies')->where('tenant_id', $tid)->where('kind', 'duplicate_charge')->pluck('reference')->sort()->values()->all();

    expect(in_array($ganador, ['fake_pay_1', 'pi_otro'], true))->toBeTrue($ganador)
        ->and(DB::table('payments')->where('id', $pago->id)->value('status'))->toBe('succeeded')
        ->and(DB::table('payments')->where('invoice_id', $pago->invoice_id)->count())->toBe(1)
        ->and(DB::table('invoices')->where('id', $pago->invoice_id)->value('status'))->toBe('paid')
        ->and(DB::table('subscriptions')->where('id', $sid)->value('current_period_start'))->toBe($finAnterior)
        // Todo cobro que cobró en la pasarela y no es el ganador está en la bandeja: nada de dinero «perdido» en silencio.
        ->and($registrados)->toBe($perdedores, json_encode($results));
});

test('una cancelación inmediata y una renovación a la vez en tres empresas nunca dejan un cobro vivo sobre una factura anulada (6 procesos)', function () {
    $empresas = array_map(fn (string $n) => ccxEmpresa($this->sufijo, 'c'.$n), ['1', '2', '3']);
    $datos = array_map(fn ($e) => [$e['tenant']->id, $e['owner']->id, $e['sub']->id], $empresas);

    $results = ConcurrentRunner::run(6, function (int $i) use ($datos) {
        config(['multi-tenancy.enabled' => true]);
        [$tid, $uid, $sid] = $datos[intdiv($i, 2)];
        $tenant = Tenant::query()->findOrFail($tid);

        if ($i % 2 === 0) {
            return ['kind' => 'renew', 'res' => app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        }

        app(TenantContext::class)->run($tenant, fn () => app(SubscriptionServiceInterface::class)->cancel($tenant, User::query()->findOrFail($uid), true));

        return ['kind' => 'cancel', 'res' => 'ok', 'charges' => 0];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    foreach ($datos as [$tid, $uid, $sid]) {
        expect(DB::table('subscriptions')->where('id', $sid)->value('status'))->toBe('canceled')
            ->and(DB::table('invoices')->where('tenant_id', $tid)->where('status', 'open')->count())->toBe(0)
            ->and(DB::table('payments')->where('tenant_id', $tid)->whereIn('status', ['pending', 'requires_action'])->count())->toBe(0)
            // Ningún cobro de pasarela `succeeded` (vivo) sobre una factura anulada: o se saldó la factura o se reembolsó.
            ->and(DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('payments.tenant_id', $tid)
                ->where('payments.status', 'succeeded')->where('payments.method', 'gateway')->where('invoices.status', 'void')->count())->toBe(0, json_encode($results));
    }
});

test('una cancelación al fin del periodo y una renovación a la vez: o se cobra y la baja queda para el siguiente, o se cancela SIN cobrar (4 procesos)', function () {
    $empresas = array_map(fn (string $n) => ccxEmpresa($this->sufijo, 'f'.$n), ['1', '2']);
    $datos = array_map(fn ($e) => [$e['tenant']->id, $e['owner']->id, $e['sub']->id], $empresas);

    $results = ConcurrentRunner::run(4, function (int $i) use ($datos) {
        config(['multi-tenancy.enabled' => true]);
        [$tid, $uid, $sid] = $datos[intdiv($i, 2)];
        $tenant = Tenant::query()->findOrFail($tid);

        if ($i % 2 === 0) {
            return ['res' => app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        }

        app(TenantContext::class)->run($tenant, fn () => app(SubscriptionServiceInterface::class)->cancel($tenant, User::query()->findOrFail($uid), false));

        return ['res' => 'ok', 'charges' => 0];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    foreach ($datos as $k => [$tid, $uid, $sid]) {
        $sub = DB::table('subscriptions')->where('id', $sid)->first();
        $cobrado = DB::table('invoices')->where('subscription_id', $sid)->where('billing_reason', 'subscription_cycle')->where('status', 'paid')->count();
        $cargos = (int) $results[$k * 2]['result']['charges'];

        if ($sub->status === 'canceled') {
            expect($cobrado)->toBe(0)->and($cargos)->toBe(0, 'cancelada pero se cobró');
        } else {
            expect($sub->status)->toBe('active')->and((bool) $sub->cancel_at_period_end)->toBeTrue()->and($cobrado)->toBe(1)->and($cargos)->toBe(1);
        }
    }
});

test('con numeración por empresa, seis emisiones simultáneas de tres empresas reciben 1..3 en cada una, sin repetir ni dejar huecos (6 procesos)', function () {
    config(['billing.invoice.sequence_scope' => 'tenant']);
    $empresas = array_map(fn (string $n) => ccxEmpresa($this->sufijo, 'n'.$n, vencida: false), ['1', '2', '3']);   // cada alta usó el 1
    $datos = array_map(fn ($e) => [$e['tenant']->id, $e['sub']->id], $empresas);

    $results = ConcurrentRunner::run(6, function (int $i) use ($datos) {
        config(['multi-tenancy.enabled' => true, 'billing.invoice.sequence_scope' => 'tenant']);
        [$tid, $sid] = $datos[intdiv($i, 2)];
        $tenant = Tenant::query()->findOrFail($tid);

        return app(TenantContext::class)->run($tenant, function () use ($sid, $i) {
            $sub = Subscription::query()->findOrFail($sid);
            $inicio = now()->addDays(10 + $i);
            $factura = app(InvoiceServiceInterface::class)->issue($sub, InvoiceReason::Update, $sub->price, $inicio, $inicio->copy()->addMonth(), [
                new LineSpec('adjustment', 'Ajuste', Money::of(100, 'USD'), 1, $inicio, $inicio->copy()->addMonth()),
            ]);

            return $factura->number;
        });
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    foreach ($datos as [$tid, $sid]) {
        $numeros = DB::table('invoices')->where('tenant_id', $tid)->pluck('number')->map(fn ($n) => (int) substr($n, -6))->sort()->values()->all();

        expect($numeros)->toBe([1, 2, 3], json_encode($results))
            ->and((int) DB::table('sequences')->where('name', 'billing_invoice')->where('tenant_id', $tid)->value('next_value'))->toBe(4);
    }
});

test('seis altas simultáneas con un tope de plan de 2 API keys: exactamente dos caben y el resto recibe plan_limit_reached (6 procesos)', function () {
    $tenant = Tenant::create(['name' => 'Cobro tope', 'slug' => "carrera-cobro-{$this->sufijo}-tope", 'status' => 'active']);
    $user = User::factory()->create(['email' => "carrera-cobro-{$this->sufijo}-tope@example.com"]);
    $tenant->users()->attach($user->getKey(), ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    DB::table('tenant_limit_overrides')->insert(['tenant_id' => $tenant->id, 'key' => 'max_api_keys', 'value' => 2, 'created_at' => now(), 'updated_at' => now()]);
    [$tid, $uid] = [$tenant->id, $user->id];

    $results = ConcurrentRunner::run(6, function (int $i) use ($tid, $uid) {
        config(['multi-tenancy.enabled' => true, 'plans.enabled' => true]);

        try {
            app(TenantContext::class)->run(Tenant::query()->findOrFail($tid), fn () => app(ApiKeyServiceInterface::class)->create(User::query()->findOrFail($uid), "carrera-{$i}", []));

            return 'ok';
        } catch (PlanLimitReachedException) {
            return 'tope';
        }
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $res = array_count_values(array_column($results, 'result'));

    expect($res['ok'] ?? 0)->toBe(2, json_encode($results))
        ->and($res['tope'] ?? 0)->toBe(4, json_encode($results))
        ->and(DB::table('api_keys')->where('tenant_id', $tid)->count())->toBe(2);
});
