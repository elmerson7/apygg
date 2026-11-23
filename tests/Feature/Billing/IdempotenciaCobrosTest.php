<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

/** Idempotencia obligatoria en las rutas de dinero (docs/idempotency.md). El inventario general está en tests/Feature/Idempotency. */
test('las rutas que cobran o mueven dinero exigen la cabecera (idempotent:required), y solo esas', function () {
    $prefijo = (string) config('api.prefix', '');
    $obligatorias = [];
    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        if (in_array('idempotent:required', array_map('strval', $ruta->gatherMiddleware()), true)) {
            $uri = ltrim($ruta->uri(), '/');
            $uri = $prefijo !== '' && str_starts_with($uri, $prefijo.'/') ? substr($uri, strlen($prefijo) + 1) : $uri;
            $obligatorias[] = implode('|', array_diff($ruta->methods(), ['HEAD'])).' '.$uri;
        }
    }
    sort($obligatorias);

    expect($obligatorias)->toBe([
        'POST admin/billing/invoices/{id}/mark-paid',
        'POST admin/billing/tenants/{tenant}/free-period',
        'POST admin/billing/tenants/{tenant}/plan',
        'POST tenant/billing/invoices/{id}/pay',
        'POST tenant/billing/subscription',
        'POST tenant/billing/subscription/change',
    ]);
});

test('una ruta de dinero sin Idempotency-Key responde 400 idempotency_key_required y no cobra ni crea nada', function () {
    $this->bootBilling();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $dueno = $this->isoUser($this->a, 'owner');

    $res = $this->asHeaders($this->isoHeaders($dueno, $this->a))
        ->postJson('/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_visa']);

    $res->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    expect($this->gateway()->charges())->toBe([]);
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->count())->toBe(0)->and(Invoice::query()->count())->toBe(0));
});

test('repetir el cobro con la misma clave devuelve la respuesta guardada y no duplica suscripción, factura ni pago', function () {
    $this->bootBilling();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $dueno = $this->isoUser($this->a, 'owner');
    $cabeceras = $this->isoHeaders($dueno, $this->a) + ['Idempotency-Key' => 'alta-pro-001'];
    $cuerpo = ['plan' => 'pro', 'interval' => 'month', 'payment_token' => 'tok_visa'];

    $primera = $this->asHeaders($cabeceras)->postJson('/tenant/billing/subscription', $cuerpo)->assertCreated();
    $segunda = $this->asHeaders($cabeceras)->postJson('/tenant/billing/subscription', $cuerpo)->assertCreated();

    expect($segunda->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($segunda->json('data.id'))->toBe($primera->json('data.id'))
        ->and($this->gateway()->charges())->toHaveCount(1);
    $this->ctx->run($this->a, fn () => expect(Subscription::query()->count())->toBe(1)->and(Invoice::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1));
});

test('un periodo gratis de la plataforma sin cabecera da 400 y con la misma clave no se concede dos veces', function () {
    $this->bootBilling();
    $plataforma = User::factory()->create();
    $plataforma->assignRole(Role::where('name', 'admin')->first());
    $this->subscribeTo($this->a, $this->isoUser($this->a, 'owner'));
    $uri = '/admin/billing/tenants/'.$this->a->id.'/free-period';
    $cabeceras = $this->isoHeaders($plataforma, $this->a);
    $cuerpo = ['days' => 5, 'reason' => 'Compensación'];
    $fin = fn () => $this->ctx->run($this->a, fn () => Subscription::query()->sole()->current_period_end?->toIso8601String());
    $antes = $fin();

    $this->asHeaders($cabeceras)->postJson($uri, $cuerpo)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    expect($fin())->toBe($antes);

    $conClave = $cabeceras + ['Idempotency-Key' => 'gratis-001'];
    $this->asHeaders($conClave)->postJson($uri, $cuerpo)->assertOk();
    $trasPrimera = $fin();
    $repetida = $this->asHeaders($conClave)->postJson($uri, $cuerpo)->assertOk();

    expect($repetida->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($trasPrimera)->not->toBe($antes)
        ->and($fin())->toBe($trasPrimera);
});
