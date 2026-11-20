<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    $this->userA = $this->isoUser($this->a, 'tenant-owner');
    $this->body = ['name' => 'Hook', 'url' => 'https://example.com/hook'];
});

test('la misma clave y el mismo usuario en dos tenants son espacios distintos', function () {
    // El mismo usuario es miembro de A y de B.
    $this->addMember($this->b, $this->userA);
    $headersA = $this->isoHeaders($this->userA, $this->a);
    $headersB = $this->isoHeaders($this->userA, $this->b);

    $enA = $this->isoCall($headersA + ['Idempotency-Key' => 'k-1'], 'POST', '/webhooks', $this->body)->assertCreated();
    $enB = $this->isoCall($headersB + ['Idempotency-Key' => 'k-1'], 'POST', '/webhooks', $this->body)->assertCreated();

    expect($enB->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($enB->json('data.secret'))->not->toBe($enA->json('data.secret'))
        ->and(DB::table('idempotency_keys')->orderBy('tenant_id')->pluck('tenant_id')->all())->toBe([$this->a->id, $this->b->id]);

    // Repetir en A repite lo de A, no lo de B.
    $otra = $this->isoCall($headersA + ['Idempotency-Key' => 'k-1'], 'POST', '/webhooks', $this->body)->assertCreated();
    expect($otra->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($otra->json('data.secret'))->toBe($enA->json('data.secret'));
});

test('otro usuario del mismo tenant tampoco ve la clave de A', function () {
    $otro = $this->isoUser($this->a);

    $a = $this->isoCall($this->isoHeaders($this->userA, $this->a) + ['Idempotency-Key' => 'k-2'], 'POST', '/webhooks', $this->body)->assertCreated();
    $b = $this->isoCall($this->isoHeaders($otro, $this->a) + ['Idempotency-Key' => 'k-2'], 'POST', '/webhooks', $this->body)->assertCreated();

    expect($b->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($b->json('data.secret'))->not->toBe($a->json('data.secret'));
});

test('la clave queda guardada con el tenant activo y desaparece con el tenant', function () {
    $this->isoCall($this->isoHeaders($this->userA, $this->a) + ['Idempotency-Key' => 'k-3'], 'POST', '/webhooks', $this->body)->assertCreated();

    expect(DB::table('idempotency_keys')->where('key', 'k-3')->value('tenant_id'))->toBe($this->a->id);

    DB::table('idempotency_keys')->where('key', 'k-3')->delete();
});
