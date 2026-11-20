<?php

use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Enums\SubscriptionStatus;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

/*
| Facturación: Subscription, Invoice, Payment y BillingAccount llevan BelongsToTenant. Plantilla: tests/Support/AssertsTenantIsolation.php.
*/

beforeEach(function () {
    $this->bootBilling();
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $this->subA = $this->subscribeTo($this->a, $this->ownerA, 'pro');
    $this->publishPlan('enterprise');
    $this->subB = $this->subscribeTo($this->b, $this->ownerB, 'enterprise');
    $this->invA = $this->ctx->run($this->a, fn () => Invoice::query()->sole());
    $this->invB = $this->ctx->run($this->b, fn () => Invoice::query()->sole());
});

test('el listado de facturas desde A solo muestra las de A', function () {
    $this->assertListIsolated($this->isoHeaders($this->ownerA, $this->a), '/tenant/billing/invoices', 'id', [$this->invA->id], [$this->invB->id]);
    $this->assertListIsolated($this->isoHeaders($this->ownerB, $this->b), '/tenant/billing/invoices', 'id', [$this->invB->id], [$this->invA->id]);
});

test('ver o pagar una factura de B desde A responde 404 y no toca nada', function () {
    $this->assertForeignIdNotFound($this->isoHeaders($this->ownerA, $this->a), [
        ['GET', "/tenant/billing/invoices/{$this->invB->id}"],
        ['GET', "/tenant/billing/invoices/{$this->invB->id}/pdf"],
        ['POST', "/tenant/billing/invoices/{$this->invB->id}/pay"],
    ]);

    $this->ctx->run($this->b, fn () => expect(Payment::query()->count())->toBe(1)->and(Invoice::query()->sole()->status->value)->toBe('paid'));
});

test('la suscripción y el método de pago que se ven son los del tenant activo', function () {
    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/billing/subscription')->assertOk()->assertJsonPath('data.plan_code', 'pro');
    $this->isoCall($this->isoHeaders($this->ownerB, $this->b), 'GET', '/tenant/billing/subscription')->assertOk()->assertJsonPath('data.plan_code', 'enterprise');

    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'PUT', '/tenant/billing/payment-method', ['token' => 'tok_amex']);
    $this->ctx->run($this->a, fn () => expect(BillingAccount::query()->sole()->payment_method_id)->toBe('fake_pm_tok_amex'));
    $this->ctx->run($this->b, fn () => expect(BillingAccount::query()->sole()->payment_method_id)->toBe('fake_pm_tok_visa'));
});

test('el dueño de A no puede actuar sobre B aunque envíe su id: no es miembro (403) y B no cambia', function () {
    $this->isoCall($this->isoHeaders($this->ownerA, $this->b), 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertForbidden();

    $this->ctx->run($this->b, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active));
});

test('un tenant_id de B en el cuerpo al contratar se ignora: la suscripción queda en A', function () {
    $this->ctx->run($this->a, fn () => Subscription::query()->delete());

    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->ownerA, $this->a),
        '/tenant/billing/subscription',
        ['plan' => 'free', 'interval' => 'month'],
        fn ($res) => (int) DB::table('subscriptions')->where('id', $res->json('data.id'))->value('tenant_id'),
    );
});

test('cancelar en A no toca la suscripción de B y la numeración (global por defecto) no se repite entre empresas', function () {
    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'POST', '/tenant/billing/subscription/cancel', ['immediately' => true])->assertOk();

    $this->ctx->run($this->b, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)->and(Invoice::query()->sole()->number)->toBe('F-'.now()->format('Y').'-000002'));
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->sole()->number)->toBe('F-'.now()->format('Y').'-000001'));
});

test('el pago manual de una factura de A (plataforma) se registra en A y no en B', function () {
    $platform = User::factory()->create();
    $platform->assignRole(Role::where('name', 'admin')->first());
    $this->ctx->run($this->a, function () {
        Payment::query()->where('invoice_id', $this->invA->id)->update(['status' => 'failed']);
        Invoice::query()->whereKey($this->invA->id)->update(['status' => 'open', 'paid_at' => null]);
    });

    $this->isoCall(['Authorization' => $this->isoHeaders($platform, $this->a)['Authorization']], 'POST', "/admin/billing/invoices/{$this->invA->id}/mark-paid", ['reference' => 'R1', 'reason' => 'prueba'])->assertOk();

    $this->ctx->run($this->a, fn () => expect(Payment::query()->where('method', 'manual')->sole()->tenant_id)->toBe($this->a->id));
    $this->ctx->run($this->b, fn () => expect(Payment::query()->where('method', 'manual')->count())->toBe(0));
});

test('los modelos de facturación solo ven lo del tenant activo y fallan cerrados sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(Invoice::query()->pluck('id')->all())->toBe([$this->invA->id])->and(Subscription::query()->pluck('id')->all())->toBe([$this->subA->id])->and(Payment::query()->count())->toBe(1));
    $this->ctx->run($this->b, fn () => expect(Invoice::query()->pluck('id')->all())->toBe([$this->invB->id])->and(BillingAccount::query()->count())->toBe(1));

    $this->ctx->clear();
    foreach ([Invoice::class, Subscription::class, Payment::class, BillingAccount::class] as $model) {
        expect(fn () => $model::query()->count())->toThrow(MissingTenantContext::class);
    }
});

test('las discrepancias de pagos de A no se ven desde B y sin tenant en el contexto fallan cerrado', function () {
    $this->ctx->run($this->a, fn () => app(PaymentDiscrepancyServiceInterface::class)->record('dispute', 'stripe', 'dp_1', Payment::query()->first(), 2900, 'USD'));

    $this->ctx->run($this->a, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(1));
    $this->ctx->run($this->b, fn () => expect(PaymentDiscrepancy::query()->count())->toBe(0));
    $this->ctx->clear();
    expect(fn () => PaymentDiscrepancy::query()->count())->toThrow(MissingTenantContext::class);
});
