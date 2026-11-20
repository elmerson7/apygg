<?php

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Enums\BillingInterval;
use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales (procesos PHP a la vez) del ciclo de cobro: dos `billing:renew` sobre la misma suscripción no emiten
 * dos facturas ni cobran dos veces; la numeración de facturas no repite ni deja huecos; dos altas simultáneas dejan una
 * sola suscripción viva; dos pagos simultáneos de una misma factura cobran una vez. Los datos quedan comprometidos: se limpian a mano.
 */

beforeEach(function () {
    $this->sufijo = bin2hex(random_bytes(3));
    config(['multi-tenancy.enabled' => true, 'queue.default' => 'sync']);
    $this->seed(RoleSeeder::class);
    app(PlanCatalogInterface::class)->sync();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    DB::table('sequences')->where('name', 'billing_invoice')->whereNull('tenant_id')->delete();
});

afterEach(function () {
    $ids = DB::table('tenants')->where('slug', 'like', 'carrera-fact-%')->pluck('id');
    DB::table('payments')->whereIn('tenant_id', $ids)->delete();
    DB::table('invoice_lines')->whereIn('invoice_id', DB::table('invoices')->whereIn('tenant_id', $ids)->select('id'))->delete();
    DB::table('invoices')->whereIn('tenant_id', $ids)->delete();
    DB::table('subscriptions')->whereIn('tenant_id', $ids)->delete();
    DB::table('audit_logs')->whereIn('tenant_id', $ids)->delete();
    DB::table('tenants')->whereIn('id', $ids)->delete();
    DB::table('users')->where('email', 'like', 'carrera-fact-%@example.com')->delete();
    DB::table('sequences')->where('name', 'billing_invoice')->whereNull('tenant_id')->delete();
    DB::table('plan_prices')->delete();
    DB::table('plans')->delete();
});

/** Tenant con dueño real y suscripción `pro` activa (pagada) ya VENCIDA, lista para renovar. @return array{tenant: Tenant, owner: User, sub: Subscription} */
function ccEmpresaVencida(string $sufijo, string $n): array
{
    $tenant = Tenant::create(['name' => "Carrera {$n}", 'slug' => "carrera-fact-{$sufijo}-{$n}", 'status' => 'active']);
    $owner = User::factory()->create(['email' => "carrera-fact-{$sufijo}-{$n}@example.com"]);
    $tenant->users()->attach($owner->getKey(), ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    TenantRoles::sync($owner, $tenant, 'owner');

    $sub = app(TenantContext::class)->run($tenant, fn () => app(SubscriptionServiceInterface::class)->subscribe($tenant, $owner, 'pro', 'USD', BillingInterval::Month, 'tok_visa'));

    DB::table('subscriptions')->where('id', $sub->id)->update(['current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);

    return ['tenant' => $tenant, 'owner' => $owner, 'sub' => app(TenantContext::class)->run($tenant, fn () => $sub->refresh())];
}

test('dos procesos renovando la misma suscripción vencida emiten UNA factura y cobran UNA vez', function () {
    $e = ccEmpresaVencida($this->sufijo, 'a');
    $tid = $e['tenant']->id;
    $sid = $e['sub']->id;
    $finAnterior = $e['sub']->current_period_end->toDateTimeString();

    $results = ConcurrentRunner::run(3, function (int $i) use ($tid, $sid) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);
        $outcome = app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid));

        return ['outcome' => $outcome->value, 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $outcomes = collect($results)->pluck('result.outcome')->all();
    expect(collect($outcomes)->filter(fn ($o) => $o === 'renewed')->count())->toBe(1, json_encode($results))
        ->and(collect($outcomes)->reject(fn ($o) => $o === 'renewed')->every(fn ($o) => in_array($o, ['locked', 'not_due', 'in_progress'], true)))->toBeTrue(json_encode($results))
        ->and(collect($results)->sum('result.charges'))->toBe(1, 'la pasarela solo debe recibir un cobro');

    expect(DB::table('invoices')->where('subscription_id', $sid)->where('billing_reason', 'subscription_cycle')->count())->toBe(1)
        ->and(DB::table('invoices')->where('subscription_id', $sid)->where('billing_reason', 'subscription_cycle')->value('status'))->toBe('paid')
        ->and(DB::table('payments')->where('tenant_id', $tid)->where('status', 'succeeded')->count())->toBe(2)   // alta + renovación
        ->and(DB::table('subscriptions')->where('id', $sid)->value('current_period_start'))->toBe($finAnterior);
});

test('cuatro empresas renovando a la vez con numeración global reciben números distintos y consecutivos', function () {
    config(['billing.invoice.sequence_scope' => 'global']);
    $empresas = array_map(fn (string $n) => ccEmpresaVencida($this->sufijo, $n), ['a', 'b', 'c', 'd']);   // las 4 altas ya usaron 1..4
    $pares = array_map(fn ($e) => [$e['tenant']->id, $e['sub']->id], $empresas);

    $results = ConcurrentRunner::run(4, function (int $i) use ($pares) {
        config(['multi-tenancy.enabled' => true, 'billing.invoice.sequence_scope' => 'global']);
        [$tid, $sid] = $pares[$i];
        $tenant = Tenant::query()->findOrFail($tid);

        return app(TenantContext::class)->run($tenant, fn () => app(RenewalServiceInterface::class)->renew($sid))->value;
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
        expect($r['result'])->toBe('renewed');
    }

    $numeros = DB::table('invoices')->whereIn('subscription_id', array_column($pares, 1))->pluck('number')
        ->map(fn ($n) => (int) substr($n, -6))->sort()->values()->all();

    expect($numeros)->toBe(range(1, 8), json_encode($numeros))    // 4 altas + 4 renovaciones: sin huecos ni duplicados
        ->and((int) DB::table('sequences')->where('name', 'billing_invoice')->whereNull('tenant_id')->value('next_value'))->toBe(9);
});

test('dos altas simultáneas de la misma empresa dejan una sola suscripción viva', function () {
    $tenant = Tenant::create(['name' => 'Carrera alta', 'slug' => "carrera-fact-{$this->sufijo}-alta", 'status' => 'active']);
    $owner = User::factory()->create(['email' => "carrera-fact-{$this->sufijo}-alta@example.com"]);
    $tenant->users()->attach($owner->getKey(), ['status' => 'active', 'role' => 'owner', 'joined_at' => now()]);
    TenantRoles::sync($owner, $tenant, 'owner');
    $tid = $tenant->id;
    $uid = $owner->id;

    $results = ConcurrentRunner::run(2, function (int $i) use ($tid, $uid) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);
        $user = User::query()->findOrFail($uid);

        try {
            app(TenantContext::class)->run($tenant, fn () => app(SubscriptionServiceInterface::class)->subscribe($tenant, $user, 'pro', 'USD', BillingInterval::Month, 'tok_visa'));

            return 'ok';
        } catch (BillingException $e) {
            return $e->errorCode();
        }
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['ok', 'subscription_exists'], json_encode($results));
    expect(DB::table('subscriptions')->where('tenant_id', $tid)->count())->toBe(1)
        ->and(DB::table('invoices')->where('tenant_id', $tid)->count())->toBe(1)
        ->and(DB::table('payments')->where('tenant_id', $tid)->where('status', 'succeeded')->count())->toBe(1);
});

test('dos pagos simultáneos de la misma factura abierta cobran una sola vez', function () {
    $e = ccEmpresaVencida($this->sufijo, 'p');
    $tid = $e['tenant']->id;
    $uid = $e['owner']->id;
    $sid = $e['sub']->id;

    // Renovación vencida que la pasarela rechazó: queda la factura abierta con un intento fallido.
    app(TenantContext::class)->run($e['tenant'], function () use ($sid) {
        app(PaymentGatewayInterface::class)->declineNext();
        app(RenewalServiceInterface::class)->renew($sid);
    });
    $factura = (int) DB::table('invoices')->where('subscription_id', $sid)->where('status', 'open')->value('id');
    expect($factura)->toBeGreaterThan(0);

    $results = ConcurrentRunner::run(2, function (int $i) use ($tid, $uid, $factura) {
        config(['multi-tenancy.enabled' => true]);
        $tenant = Tenant::query()->findOrFail($tid);
        $user = User::query()->findOrFail($uid);

        try {
            app(TenantContext::class)->run($tenant, fn () => app(PaymentProcessorInterface::class)->pay($tenant, $user, $factura));

            return ['res' => 'ok', 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        } catch (BillingException $ex) {
            return ['res' => $ex->errorCode(), 'charges' => count(app(PaymentGatewayInterface::class)->calls('charge'))];
        }
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $res = collect($results)->pluck('result.res')->sort()->values()->all();
    expect(in_array('ok', $res, true))->toBeTrue(json_encode($results))
        ->and(in_array(collect($res)->reject(fn ($r) => $r === 'ok')->first(), ['invoice_not_open', 'payment_in_progress'], true))->toBeTrue(json_encode($results))
        ->and(collect($results)->sum('result.charges'))->toBe(1)
        ->and(DB::table('payments')->where('invoice_id', $factura)->where('status', 'succeeded')->count())->toBe(1);
});
