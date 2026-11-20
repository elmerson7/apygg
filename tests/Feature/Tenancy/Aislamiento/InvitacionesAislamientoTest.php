<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

use App\Models\TenantInvitation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->bootIsolation();
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->ownerB = $this->isoUser($this->b, 'owner');
    $hacer = fn ($tenant, $by, string $email) => $this->ctx->run($tenant, fn () => TenantInvitation::create([
        'email' => $email, 'role' => 'member', 'token_hash' => hash('sha256', $email), 'invited_by' => $by->id, 'expires_at' => now()->addDays(3),
    ]));
    $this->invA = $hacer($this->a, $this->ownerA, 'solo-a@example.com');
    $this->invB = $hacer($this->b, $this->ownerB, 'solo-b@example.com');
});

test('el listado de invitaciones desde A no muestra las de B', function () {
    $this->assertListIsolated($this->isoHeaders($this->ownerA, $this->a), '/tenant/invitations', 'email', ['solo-a@example.com'], ['solo-b@example.com']);
});

test('reenviar y revocar una invitación de B desde A responde 404 y no la toca', function () {
    Notification::fake();
    $id = $this->invB->id;
    $this->assertForeignIdNotFound($this->isoHeaders($this->ownerA, $this->a), [
        ['POST', "/tenant/invitations/{$id}/resend"],
        ['DELETE', "/tenant/invitations/{$id}"],
    ]);

    expect(TenantQuery::withoutTenant(fn () => TenantInvitation::find($id)->revoked_at, 'test'))->toBeNull();
    Notification::assertNothingSent();
});

test('invitar desde A ignora un tenant_id de B en el cuerpo', function () {
    Notification::fake();

    $this->assertBodyTenantIgnored(
        $this->isoHeaders($this->ownerA, $this->a),
        '/tenant/invitations',
        ['email' => 'nuevo@example.com', 'role' => 'member'],
        fn ($res) => TenantQuery::withoutTenant(fn () => TenantInvitation::findOrFail($res->json('data.id'))->tenant_id, 'test'),
    );
});

test('el dueño de A no gestiona invitaciones de B declarando X-Tenant de B', function () {
    $this->isoCall($this->isoHeaders($this->ownerA, $this->b), 'GET', '/tenant/invitations')->assertForbidden();
});

test('el modelo TenantInvitation solo ve las filas del tenant activo y falla cerrado sin contexto', function () {
    $this->ctx->run($this->a, fn () => expect(TenantInvitation::query()->pluck('email')->all())->toBe(['solo-a@example.com']));
    $this->ctx->run($this->b, fn () => expect(TenantInvitation::query()->pluck('email')->all())->toBe(['solo-b@example.com']));
    $this->ctx->clear();
    expect(fn () => TenantInvitation::query()->count())->toThrow(MissingTenantContext::class);
});
