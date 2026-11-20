<?php

use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->bootBilling();
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    Plan::query()->where('code', 'pro')->update(['trial_days' => 0]);
});

test('con numeración por tenant cada empresa numera desde 1 y la factura guarda su alcance', function () {
    config(['billing.invoice.sequence_scope' => 'tenant']);
    $this->travelTo('2026-11-20 12:00:00');
    $this->subscribeTo($this->a, $this->ownerA);
    $this->subscribeTo($this->b, $this->ownerB);

    $this->ctx->run($this->a, fn () => expect(Invoice::query()->sole()->number)->toBe('F-2026-000001')->and(Invoice::query()->sole()->number_scope)->toBe('tenant'));
    $this->ctx->run($this->b, fn () => expect(Invoice::query()->sole()->number)->toBe('F-2026-000001'));
});

test('por defecto la numeración es global: una sola serie para toda la plataforma', function () {
    expect(config('billing.invoice.sequence_scope'))->toBe('global');
    $this->travelTo('2026-11-20 12:00:00');
    $this->subscribeTo($this->a, $this->ownerA);
    $this->subscribeTo($this->b, $this->ownerB);

    $this->ctx->run($this->a, fn () => expect(Invoice::query()->sole()->number)->toBe('F-2026-000001')->and(Invoice::query()->sole()->number_scope)->toBe('global'));
    $this->ctx->run($this->b, fn () => expect(Invoice::query()->sole()->number)->toBe('F-2026-000002'));
});
