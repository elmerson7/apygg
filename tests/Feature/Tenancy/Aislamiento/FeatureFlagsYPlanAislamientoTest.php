<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Contracts\FeatureFlagServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    $this->bootIsolation();
    config(['features.demo' => ['enabled' => false, 'description' => 'demo'], 'plans.enabled' => true]);
    $this->userA = $this->isoUser($this->a, null, ['tenant-settings.read']);
    $this->userB = $this->isoUser($this->b, null, ['tenant-settings.read']);
    $this->plataforma = ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];
});

test('un override de feature flag para A lo ve A y no B', function () {
    $this->isoCall($this->plataforma, 'PUT', "/admin/feature-flags/demo/tenants/{$this->a->id}", ['enabled' => true])->assertOk();

    $this->isoCall($this->isoHeaders($this->userA, $this->a), 'GET', '/tenant/features')->assertOk()->assertJsonPath('data.demo.enabled', true);
    $this->isoCall($this->isoHeaders($this->userB, $this->b), 'GET', '/tenant/features')->assertOk()->assertJsonPath('data.demo.enabled', false);

    $flags = app(FeatureFlagServiceInterface::class);
    $this->ctx->run($this->a, fn () => expect($flags->enabled('demo'))->toBeTrue());
    $this->ctx->run($this->b, fn () => expect($flags->enabled('demo'))->toBeFalse());
});

test('quitar el override de B no toca el de A', function () {
    $this->isoCall($this->plataforma, 'PUT', "/admin/feature-flags/demo/tenants/{$this->a->id}", ['enabled' => true])->assertOk();
    $this->isoCall($this->plataforma, 'PUT', "/admin/feature-flags/demo/tenants/{$this->b->id}", ['enabled' => true])->assertOk();
    $this->isoCall($this->plataforma, 'DELETE', "/admin/feature-flags/demo/tenants/{$this->b->id}")->assertOk();

    $this->isoCall($this->isoHeaders($this->userA, $this->a), 'GET', '/tenant/features')->assertJsonPath('data.demo.enabled', true);
    $this->isoCall($this->isoHeaders($this->userB, $this->b), 'GET', '/tenant/features')->assertJsonPath('data.demo.enabled', false);
});

test('un usuario de tenant no gestiona flags ni planes de plataforma', function () {
    $h = $this->isoHeaders($this->userA, $this->a);

    $this->isoCall($h, 'PUT', "/admin/feature-flags/demo/tenants/{$this->b->id}", ['enabled' => true])->assertForbidden();
    $this->isoCall($h, 'PUT', "/admin/tenants/{$this->b->id}/plan", ['plan' => 'pro'])->assertForbidden();
    $this->isoCall($h, 'PUT', "/admin/tenants/{$this->b->id}/limits/max_api_keys", ['value' => 99])->assertForbidden();
    $this->isoCall($h, 'GET', "/admin/tenants/{$this->b->id}/plan")->assertForbidden();
});

test('el plan y los overrides de límites de A no cambian los de B', function () {
    $this->isoCall($this->plataforma, 'PUT', "/admin/tenants/{$this->a->id}/plan", ['plan' => 'pro'])->assertOk();
    $this->isoCall($this->plataforma, 'PUT', "/admin/tenants/{$this->a->id}/limits/max_api_keys", ['value' => 9])->assertOk();

    $this->isoCall($this->isoHeaders($this->userA, $this->a), 'GET', '/tenant/limits')->assertOk()
        ->assertJsonPath('data.plan', 'pro')->assertJsonPath('data.limits.max_api_keys.limit', 9);
    $this->isoCall($this->isoHeaders($this->userB, $this->b), 'GET', '/tenant/limits')->assertOk()
        ->assertJsonPath('data.plan', 'free')->assertJsonPath('data.limits.max_api_keys.limit', 2);

    $limits = app(PlanLimitsInterface::class);
    expect($limits->limit('max_api_keys', $this->a->id))->toBe(9)
        ->and($limits->limit('max_api_keys', $this->b->id))->toBe(2);
});

test('el tenant lee solo su propio plan: sin X-Tenant del otro y con 403 si lo declara', function () {
    $this->isoCall($this->isoHeaders($this->userA, $this->b), 'GET', '/tenant/limits')->assertForbidden();
    $this->isoCall($this->isoHeaders($this->userA, $this->b), 'GET', '/tenant/features')->assertForbidden();
});
