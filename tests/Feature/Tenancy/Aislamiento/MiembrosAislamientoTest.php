<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->bootIsolation();
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $this->miembroB = $this->isoUser($this->b);
    // Una persona en ambos tenants: lo que se haga en A no puede alterar su fila en B
    $this->doble = $this->isoUser($this->a);
    $this->addMember($this->b, $this->doble);
    $this->estado = fn ($tenant, $user) => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('status');
    $this->rol = fn ($tenant, $user) => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role');
});

test('el listado de miembros desde A no muestra a los miembros exclusivos de B', function () {
    $ids = collect($this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/members')->assertOk()->json('data'))->pluck('user_id')->all();

    expect($ids)->toContain($this->ownerA->id, $this->doble->id)
        ->and($ids)->not->toContain($this->ownerB->id)
        ->and($ids)->not->toContain($this->miembroB->id);
});

test('cambiar rol, suspender, reactivar y quitar a un miembro exclusivo de B desde A responde 404', function () {
    $id = $this->miembroB->id;
    $this->assertForeignIdNotFound($this->isoHeaders($this->ownerA, $this->a), [
        ['PATCH', "/tenant/members/{$id}", ['role' => 'admin']],
        ['POST', "/tenant/members/{$id}/suspend"],
        ['POST', "/tenant/members/{$id}/reactivate"],
        ['DELETE', "/tenant/members/{$id}"],
    ]);

    expect(($this->rol)($this->b, $this->miembroB))->toBe('member')
        ->and(($this->estado)($this->b, $this->miembroB))->toBe('active');
});

test('suspender o cambiar el rol en A a quien está en ambos tenants no toca su fila de B, aunque el cuerpo traiga el tenant de B', function () {
    $h = $this->isoHeaders($this->ownerA, $this->a);

    $this->isoCall($h, 'PATCH', "/tenant/members/{$this->doble->id}", ['role' => 'admin', 'tenant_id' => $this->b->id])->assertOk();
    expect(($this->rol)($this->a, $this->doble))->toBe('admin')
        ->and(($this->rol)($this->b, $this->doble))->toBe('member');

    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'POST', "/tenant/members/{$this->doble->id}/suspend", ['tenant_id' => $this->b->id])->assertOk();
    expect(($this->estado)($this->a, $this->doble))->toBe('suspended')
        ->and(($this->estado)($this->b, $this->doble))->toBe('active');

    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'DELETE', "/tenant/members/{$this->doble->id}")->assertOk();
    expect(($this->estado)($this->a, $this->doble))->toBeNull()
        ->and(($this->estado)($this->b, $this->doble))->toBe('active');
});

test('el dueño de A no gestiona miembros de B declarando X-Tenant de B', function () {
    $h = $this->isoHeaders($this->ownerA, $this->b);

    $this->isoCall($h, 'GET', '/tenant/members')->assertForbidden();
    $this->isoCall($h, 'DELETE', "/tenant/members/{$this->miembroB->id}")->assertForbidden();
});
