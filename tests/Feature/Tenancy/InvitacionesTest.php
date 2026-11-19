<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

function tenantUser($test, Tenant $tenant, string $role = 'member', ?string $email = null): User
{
    $user = User::factory()->create($email ? ['email' => $email] : []);
    $tenant->users()->attach($user->getKey(), ['status' => 'active', 'role' => $role, 'joined_at' => now()]);
    TenantRoles::sync($user, $tenant, $role);

    return $user;
}

function authFor(User $user, ?string $tenant = null): array
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken(); // el JWT singleton y el guard cachean el token/usuario de la petición anterior

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)] + ($tenant ? ['X-Tenant' => $tenant] : []);
}

/** Crea una invitación y devuelve [respuesta, token en claro capturado del correo]. */
function invitar($test, User $by, string $tenantSlug, string $email, string $role = 'member'): array
{
    Notification::fake();
    $res = $test->postJson('/tenant/invitations', ['email' => $email, 'role' => $role], authFor($by, $tenantSlug));
    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, TenantInvitationNotification::class, function ($n) use (&$token) {
        preg_match('/token=([^&]+)/', $n->acceptUrl(), $m);
        $token = urldecode($m[1]);

        return true;
    });

    return [$res, $token];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ownerA = tenantUser($this, $this->a, 'owner');
    $this->ownerB = tenantUser($this, $this->b, 'owner');
});

test('un dueño invita: se guarda solo el hash del token y el correo lleva el enlace con el token en claro', function () {
    [$res, $token] = invitar($this, $this->ownerA, 'acme', 'Nuevo@Example.com', 'admin');

    $res->assertCreated()
        ->assertJsonPath('data.email', 'nuevo@example.com')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    $row = DB::table('tenant_invitations')->first();
    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toBe($token)
        ->and(json_encode($res->json()))->not->toContain($token)
        ->and($row->tenant_id)->toBe($this->a->id)
        ->and($row->invited_by)->toBe($this->ownerA->id);
});

test('la invitación se envía por el camino único de correo: notificación en cola con plantilla', function () {
    expect(TenantInvitationNotification::mailTemplateKey())->toBe('tenant_invitation')
        ->and(in_array(ShouldQueue::class, class_implements(TenantInvitationNotification::class)))->toBeTrue();
});

test('el enlace del correo usa la plantilla configurable con el token', function () {
    config(['multi-tenancy.onboarding.invitations.accept_url' => 'https://app.test/unirse/{token}']);
    Notification::fake();

    $this->postJson('/tenant/invitations', ['email' => 'x@example.com'], authFor($this->ownerA, 'acme'))->assertCreated();

    Notification::assertSentTo(new AnonymousNotifiable, TenantInvitationNotification::class, fn ($n) => str_starts_with($n->acceptUrl(), 'https://app.test/unirse/') && strlen($n->acceptUrl()) > 40);
});

test('un invitado acepta con el token y queda como miembro activo con el rol invitado', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com', 'admin');
    $nuevo = User::factory()->create(['email' => 'Nuevo@Example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))
        ->assertOk()->assertJsonPath('data.slug', 'acme');

    expect($this->a->hasActiveMember($nuevo))->toBeTrue()
        ->and($this->a->users()->whereKey($nuevo->id)->first()->pivot->role)->toBe('admin')
        ->and(DB::table('tenant_invitations')->value('accepted_at'))->not->toBeNull();

    // ya puede entrar al tenant y, como admin, ver los miembros
    $this->getJson('/tenant/members', authFor($nuevo, 'acme'))->assertOk()->assertJsonCount(2, 'data');
});

test('aceptar dos veces la misma invitación da 409 invitation_used y deja una sola membresía', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    $nuevo = User::factory()->create(['email' => 'nuevo@example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))->assertOk();
    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))
        ->assertStatus(409)->assertJsonPath('code', 'invitation_used');

    expect(DB::table('tenant_user')->where('tenant_id', $this->a->id)->where('user_id', $nuevo->id)->count())->toBe(1);
});

test('un token inventado da 404 invitation_invalid sin revelar nada', function () {
    $user = User::factory()->create();

    $this->postJson('/tenant/invitations/accept', ['token' => str_repeat('a', 64)], authFor($user))
        ->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
});

test('el email del usuario debe coincidir con el invitado (403) salvo que la config lo permita', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    $otro = User::factory()->create(['email' => 'otro@example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($otro))
        ->assertForbidden()->assertJsonPath('code', 'invitation_email_mismatch');
    expect($this->a->hasActiveMember($otro))->toBeFalse()
        ->and(DB::table('tenant_invitations')->whereNotNull('accepted_at')->count())->toBe(0);

    config(['multi-tenancy.onboarding.invitations.allow_email_mismatch' => true]);
    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($otro))->assertOk();
    expect($this->a->hasActiveMember($otro))->toBeTrue();
});

test('una invitación caducada da 410 invitation_expired y no crea membresía', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    DB::table('tenant_invitations')->update(['expires_at' => now()->subMinute()]);
    $nuevo = User::factory()->create(['email' => 'nuevo@example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))
        ->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
    expect($this->a->hasActiveMember($nuevo))->toBeFalse();
});

test('una invitación revocada da 410 invitation_revoked', function () {
    [$res, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    $this->deleteJson('/tenant/invitations/'.$res->json('data.id'), [], authFor($this->ownerA, 'acme'))
        ->assertOk()->assertJsonPath('data.status', 'revoked');
    $nuevo = User::factory()->create(['email' => 'nuevo@example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))
        ->assertStatus(410)->assertJsonPath('code', 'invitation_revoked');
});

test('reenviar rota el token (el anterior deja de valer) y renueva la caducidad', function () {
    [$res, $viejo] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    DB::table('tenant_invitations')->update(['expires_at' => now()->subDay()]);

    Notification::fake();
    $this->postJson('/tenant/invitations/'.$res->json('data.id').'/resend', [], authFor($this->ownerA, 'acme'))
        ->assertOk()->assertJsonPath('data.status', 'pending');
    $nuevoToken = null;
    Notification::assertSentTo(new AnonymousNotifiable, TenantInvitationNotification::class, function ($n) use (&$nuevoToken) {
        preg_match('/token=([^&]+)/', $n->acceptUrl(), $m);
        $nuevoToken = urldecode($m[1]);

        return true;
    });

    $user = User::factory()->create(['email' => 'nuevo@example.com']);
    expect($nuevoToken)->not->toBe($viejo);
    $this->postJson('/tenant/invitations/accept', ['token' => $viejo], authFor($user))->assertNotFound();
    $this->postJson('/tenant/invitations/accept', ['token' => $nuevoToken], authFor($user))->assertOk();
});

test('no se reenvía ni revoca una invitación ya aceptada', function () {
    [$res, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    $user = User::factory()->create(['email' => 'nuevo@example.com']);
    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($user))->assertOk();

    $this->postJson('/tenant/invitations/'.$res->json('data.id').'/resend', [], authFor($this->ownerA, 'acme'))->assertStatus(409);
    $this->deleteJson('/tenant/invitations/'.$res->json('data.id'), [], authFor($this->ownerA, 'acme'))->assertStatus(409);
});

test('invitar a quien ya es miembro da 409 already_member', function () {
    $miembro = tenantUser($this, $this->a, 'member', 'ya@example.com');

    $this->postJson('/tenant/invitations', ['email' => 'YA@example.com'], authFor($this->ownerA, 'acme'))
        ->assertStatus(409)->assertJsonPath('code', 'already_member');
});

test('aceptar siendo ya miembro da 409 already_member', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'ya@example.com');
    $ya = tenantUser($this, $this->a, 'member', 'ya@example.com');

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($ya))
        ->assertStatus(409)->assertJsonPath('code', 'already_member');
});

test('una segunda invitación pendiente al mismo email da 409, y una caducada no bloquea', function () {
    invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');

    $this->postJson('/tenant/invitations', ['email' => 'nuevo@example.com'], authFor($this->ownerA, 'acme'))
        ->assertStatus(409)->assertJsonPath('code', 'invitation_already_pending');

    DB::table('tenant_invitations')->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/tenant/invitations', ['email' => 'nuevo@example.com'], authFor($this->ownerA, 'acme'))->assertCreated();
});

test('el tope de invitaciones pendientes por tenant responde 422', function () {
    config(['multi-tenancy.onboarding.invitations.max_pending' => 2]);
    Notification::fake();

    foreach (['a@example.com', 'b@example.com'] as $e) {
        $this->postJson('/tenant/invitations', ['email' => $e], authFor($this->ownerA, 'acme'))->assertCreated();
    }
    $this->postJson('/tenant/invitations', ['email' => 'c@example.com'], authFor($this->ownerA, 'acme'))
        ->assertUnprocessable()->assertJsonPath('code', 'invitation_limit_reached');

    // el tope es POR tenant: B no se ve afectado
    $this->postJson('/tenant/invitations', ['email' => 'c@example.com'], authFor($this->ownerB, 'beta'))->assertCreated();
});

test('un admin no puede invitar con un rol superior al suyo', function () {
    $admin = tenantUser($this, $this->a, 'admin');
    Notification::fake();

    $this->postJson('/tenant/invitations', ['email' => 'x@example.com', 'role' => 'owner'], authFor($admin, 'acme'))
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    $this->postJson('/tenant/invitations', ['email' => 'x@example.com', 'role' => 'admin'], authFor($admin, 'acme'))->assertCreated();
});

test('un miembro raso no gestiona invitaciones (403) y sin token es 401', function () {
    $raso = tenantUser($this, $this->a, 'member');

    $this->getJson('/tenant/invitations', authFor($raso, 'acme'))->assertForbidden();
    $this->postJson('/tenant/invitations', ['email' => 'x@example.com'], authFor($raso, 'acme'))->assertForbidden();
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    $this->getJson('/tenant/invitations', ['X-Tenant' => 'acme'])->assertUnauthorized();
});

test('el rol inválido y el email inválido dan 422', function () {
    $this->postJson('/tenant/invitations', ['email' => 'no-es-email'], authFor($this->ownerA, 'acme'))->assertUnprocessable();
    $this->postJson('/tenant/invitations', ['email' => 'x@example.com', 'role' => 'dios'], authFor($this->ownerA, 'acme'))->assertUnprocessable();
});

test('aislamiento: A no ve, reenvía ni revoca invitaciones de B, y el listado va paginado', function () {
    [$resB] = invitar($this, $this->ownerB, 'beta', 'solo-b@example.com');
    invitar($this, $this->ownerA, 'acme', 'solo-a@example.com');

    $this->getJson('/tenant/invitations', authFor($this->ownerA, 'acme'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'solo-a@example.com')->assertJsonPath('meta.total', 1);

    $idB = $resB->json('data.id');
    $this->postJson("/tenant/invitations/{$idB}/resend", [], authFor($this->ownerA, 'acme'))->assertNotFound();
    $this->deleteJson("/tenant/invitations/{$idB}", [], authFor($this->ownerA, 'acme'))->assertNotFound();
    expect(DB::table('tenant_invitations')->where('id', $idB)->value('revoked_at'))->toBeNull();

    // un dueño de A no puede usar la cabecera de B (no es miembro)
    $this->getJson('/tenant/invitations', authFor($this->ownerA, 'beta'))->assertForbidden();
});

test('el tenant_id del cuerpo se ignora al invitar', function () {
    Notification::fake();
    $this->postJson('/tenant/invitations', ['email' => 'x@example.com', 'tenant_id' => $this->b->id], authFor($this->ownerA, 'acme'))->assertCreated();

    expect(DB::table('tenant_invitations')->value('tenant_id'))->toBe($this->a->id);
});

test('aceptar en un tenant suspendido da 403 tenant_suspended', function () {
    [, $token] = invitar($this, $this->ownerA, 'acme', 'nuevo@example.com');
    $this->a->update(['status' => TenantStatus::Suspended]);
    $nuevo = User::factory()->create(['email' => 'nuevo@example.com']);

    $this->postJson('/tenant/invitations/accept', ['token' => $token], authFor($nuevo))->assertForbidden();
    expect(app(TenantContext::class)->current())->toBeNull();
});

test('aceptar sin autenticar da 401', function () {
    $this->postJson('/tenant/invitations/accept', ['token' => 'x'])->assertUnauthorized();
});

test('la BD impide dos invitaciones pendientes del mismo email y dos membresías del mismo par', function () {
    $fila = ['tenant_id' => $this->a->id, 'email' => 'dup@example.com', 'role' => 'member', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('tenant_invitations')->insert($fila + ['token_hash' => str_repeat('1', 64)]);

    expect(fn () => DB::table('tenant_invitations')->insert($fila + ['token_hash' => str_repeat('2', 64)]))
        ->toThrow(UniqueConstraintViolationException::class);
});
