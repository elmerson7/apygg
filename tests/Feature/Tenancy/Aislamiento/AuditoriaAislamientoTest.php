<?php

use App\Models\AuditLog;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

/*
| audit_logs NO usa BelongsToTenant (la plataforma ve todo): el aislamiento lo da AuditService, que filtra SIEMPRE por el
| tenant del contexto. Aquí se prueba que A no ve ni exporta lo de B por ninguna vía.
*/

beforeEach(function () {
    $this->bootIsolation();
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $hacer = fn ($tenant, $user, string $name) => $this->ctx->run($tenant, fn () => Webhook::factory()->create(['user_id' => $user->id, 'name' => $name, 'status' => 'active', 'events' => null, 'url' => 'https://example.com/h', 'secret' => str_repeat('s', 40)]));
    $this->webhookA = $hacer($this->a, $this->ownerA, 'secreto-de-a');
    $this->webhookB = $hacer($this->b, $this->ownerB, 'secreto-de-b');
});

test('la auditoría de A solo contiene entradas de A y la de B solo las de B', function () {
    $idsA = collect($this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/audit?model=Webhook')->assertOk()->json('data'))->pluck('auditable_id')->all();
    $idsB = collect($this->isoCall($this->isoHeaders($this->ownerB, $this->b), 'GET', '/tenant/audit?model=Webhook')->assertOk()->json('data'))->pluck('auditable_id')->all();

    expect($idsA)->toBe([(string) $this->webhookA->id])
        ->and($idsB)->toBe([(string) $this->webhookB->id]);
});

test('un tenant_id de B en la query o el filtro de la auditoría de A se ignora', function () {
    $res = $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', "/tenant/audit?tenant_id={$this->b->id}&model=Webhook")->assertOk();

    expect(collect($res->json('data'))->pluck('tenant_id')->unique()->values()->all())->toBe([$this->a->id]);
});

test('la exportación CSV de A no incluye nada de B', function () {
    $csv = $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/audit/export')->assertOk()->streamedContent();

    expect($csv)->toContain('secreto-de-a')->not->toContain('secreto-de-b')
        ->and($csv)->not->toContain((string) $this->webhookB->id."\"\n");

    $filas = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
    $tenants = collect(array_slice($filas, 1))->pluck(2)->unique()->values()->all();
    expect($tenants)->toBe([(string) $this->a->id]);
});

test('quien es miembro de A y tiene permiso en A no puede leer la auditoría de B con el X-Tenant de B', function () {
    // ownerA no es miembro de B: el middleware `tenant` lo rechaza
    $this->isoCall($this->isoHeaders($this->ownerA, $this->b), 'GET', '/tenant/audit')->assertForbidden();
    $this->isoCall($this->isoHeaders($this->ownerA, $this->b), 'GET', '/tenant/audit/export')->assertForbidden();
});

test('las entradas de auditoría de B siguen intactas tras consultar la de A', function () {
    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/audit')->assertOk();

    expect(AuditLog::query()->where('tenant_id', $this->b->id)->where('auditable_id', (string) $this->webhookB->id)->count())->toBe(1);
});
