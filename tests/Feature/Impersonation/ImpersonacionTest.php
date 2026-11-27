<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->soporte = User::factory()->create();
    $this->soporte->assignRole(Role::where('name', 'user')->first());
    $this->soporte->givePermissionTo('users.impersonate');
    $this->objetivo = User::factory()->create();
    $this->objetivo->assignRole(Role::where('name', 'user')->first());
});

function impersonarComo($test, User $actor, User $target, array $body = [])
{
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($actor)];

    return $test->asHeaders($headers)->postJson("/admin/impersonate/{$target->id}", $body);
}

function actuarImpersonado($test, string $token, string $method, string $uri, array $body = [], array $extra = [])
{
    return $test->asHeaders(['Authorization' => 'Bearer '.$token] + $extra)->json($method, $uri, $body);
}

test('soporte con permiso y motivo obtiene un token de corta duración sin refresh', function () {
    $res = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket 123'])->assertOk();

    $token = $res->json('data.access_token');
    $claims = JWTAuth::setToken($token)->getPayload();

    expect($res->json('data.refresh_token'))->toBeNull()
        ->and($res->json('data.expires_in'))->toBe(900)
        ->and((int) $claims->get('imp'))->toBe($this->soporte->id)
        ->and((int) $claims->get('sub'))->toBe($this->objetivo->id)
        ->and($claims->get('type'))->toBe('access')
        ->and($claims->get('exp') - $claims->get('iat'))->toBeLessThanOrEqual(900);
});

test('el token actúa como el usuario suplantado y la respuesta lleva X-Impersonating', function () {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket 123'])->json('data.access_token');

    $res = actuarImpersonado($this, $token, 'GET', '/auth/me')->assertOk();

    expect($res->headers->get('X-Impersonating'))->toBe((string) $this->soporte->id);
    expect(json_encode($res->json()))->toContain($this->objetivo->email);
});

test('una petición normal no lleva X-Impersonating', function () {
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->objetivo)];

    $res = $this->asHeaders($headers)->getJson('/auth/me')->assertOk();

    expect($res->headers->has('X-Impersonating'))->toBeFalse();
});

test('sin permiso no se puede impersonar', function () {
    $otro = User::factory()->create();
    $otro->assignRole(Role::where('name', 'user')->first());

    impersonarComo($this, $otro, $this->objetivo, ['reason' => 'x'])->assertForbidden();
});

test('sin sesión no se puede impersonar', function () {
    $this->asHeaders([])->postJson("/admin/impersonate/{$this->objetivo->id}", ['reason' => 'x'])->assertUnauthorized();
});

test('sin motivo se rechaza con audit_reason_required y no se emite token', function () {
    $res = impersonarComo($this, $this->soporte, $this->objetivo)->assertStatus(422);

    expect($res->json('code'))->toBe('audit_reason_required')
        ->and(AuditLog::query()->where('event', 'impersonation.start')->count())->toBe(0);
});

test('no se puede impersonar a un super admin', function () {
    $admin = User::factory()->admin()->create();

    $res = impersonarComo($this, $this->soporte, $admin, ['reason' => 'x'])->assertForbidden();

    expect($res->json('code'))->toBe('impersonation_target_forbidden');
});

test('no se puede impersonar a quien tiene permisos que el actor no tiene', function () {
    $this->objetivo->givePermissionTo('users.delete');

    $res = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'x'])->assertForbidden();

    expect($res->json('code'))->toBe('impersonation_target_forbidden');
});

test('no se puede impersonar a otro con permiso de impersonar ni a uno mismo', function () {
    $par = User::factory()->create();
    $par->givePermissionTo('users.impersonate');

    impersonarComo($this, $this->soporte, $par, ['reason' => 'x'])->assertForbidden();
    impersonarComo($this, $this->soporte, $this->soporte, ['reason' => 'x'])->assertForbidden();
});

test('un super admin puede impersonar a un usuario normal', function () {
    $admin = User::factory()->admin()->create();

    impersonarComo($this, $admin, $this->objetivo, ['reason' => 'Soporte'])->assertOk();
});

test('un usuario inexistente da 404', function () {
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->soporte)];

    $this->asHeaders($headers)->postJson('/admin/impersonate/999999', ['reason' => 'x'])->assertNotFound();
});

test('impersonando no se puede volver a impersonar', function () {
    $this->objetivo->givePermissionTo('users.impersonate');
    $token = JWTAuth::claims(['imp' => $this->soporte->id])->fromUser($this->objetivo);
    $otro = User::factory()->create();

    actuarImpersonado($this, $token, 'POST', "/admin/impersonate/{$otro->id}", ['reason' => 'x'])->assertForbidden();
});

test('las rutas sensibles quedan bloqueadas con code estable', function (string $method, string $uri) {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket'])->json('data.access_token');

    $res = actuarImpersonado($this, $token, $method, $uri, ['current_password' => 'x', 'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123', 'name' => 'x']);

    expect($res->status())->toBe(403)
        ->and($res->json('code'))->toBe('impersonation_forbidden');
})->with([
    ['POST', '/auth/change-password'],
    ['PUT', '/user/profile'],
    ['POST', '/api-keys'],
    ['DELETE', '/api-keys/1'],
    ['DELETE', '/users/1'],
    ['PUT', '/users/1'],
    ['POST', '/user/2fa/setup'],
    ['DELETE', '/user/2fa'],
    ['DELETE', '/user/sessions'],
    // H3: lo que sobreviviría a la impersonación (invitarse, subir un rol, webhooks, cobros)
    ['POST', '/tenant/invitations'],
    ['DELETE', '/tenant/invitations/1'],
    ['PATCH', '/tenant/members/1'],
    ['DELETE', '/tenant/members/1'],
    ['POST', '/tenant/members/1/suspend'],
    ['POST', '/tenants'],
    ['POST', '/webhooks'],
    ['PUT', '/webhooks/1'],
    ['POST', '/tenant/billing/subscription'],
    ['POST', '/tenant/billing/subscription/change'],
    ['POST', '/roles'],
    ['PUT', '/roles/1/permissions'],
]);

test('las rutas normales siguen funcionando impersonando', function () {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket'])->json('data.access_token');

    actuarImpersonado($this, $token, 'GET', '/user/profile')->assertOk();
});

test('start y stop quedan auditados con el impersonador como actor real', function () {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket 77'])->json('data.access_token');

    $inicio = AuditLog::query()->where('event', 'impersonation.start')->firstOrFail();

    expect($inicio->actor_id)->toBe((string) $this->soporte->id)
        ->and($inicio->reason)->toBe('Ticket 77')
        ->and($inicio->auditable_id)->toBe((string) $this->objetivo->id);

    actuarImpersonado($this, $token, 'POST', '/admin/impersonate/stop')->assertOk();

    $fin = AuditLog::query()->where('event', 'impersonation.stop')->firstOrFail();

    expect($fin->actor_id)->toBe((string) $this->objetivo->id)
        ->and($fin->impersonator_id)->toBe((string) $this->soporte->id);
});

test('tras stop el token deja de valer', function () {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket'])->json('data.access_token');

    actuarImpersonado($this, $token, 'POST', '/admin/impersonate/stop')->assertOk();

    actuarImpersonado($this, $token, 'GET', '/auth/me')->assertUnauthorized();
});

test('stop con una sesión normal responde 409 not_impersonating', function () {
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->objetivo)];

    $res = $this->asHeaders($headers)->postJson('/admin/impersonate/stop')->assertStatus(409);

    expect($res->json('code'))->toBe('not_impersonating');
});

test('impersonar acotado a un tenant fija el tenant y rechaza otro', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $this->addMember($a, $this->objetivo);
    $this->addMember($b, $this->objetivo);

    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'x', 'tenant_id' => $a->id])->assertOk()->json('data.access_token');

    actuarImpersonado($this, $token, 'GET', '/user/profile', [], ['X-Tenant' => (string) $b->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'impersonation_tenant_mismatch');
});

test('impersonar en un tenant donde el objetivo no es miembro se rechaza', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $this->addMember($a, $this->objetivo);

    impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'x', 'tenant_id' => $b->id])->assertForbidden();
});

test('con la impersonación desactivada se rechaza', function () {
    config(['impersonation.enabled' => false]);

    $res = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'x'])->assertForbidden();

    expect($res->json('code'))->toBe('impersonation_disabled');
});

test('el token lleva sid de una sesión propia y vale con AUTH_REQUIRE_SESSION activo', function () {
    $token = impersonarComo($this, $this->soporte, $this->objetivo, ['reason' => 'Ticket'])->json('data.access_token');
    config(['auth.sessions.require_sid' => true]);

    expect(JWTAuth::setToken($token)->getPayload()->get('sid'))->toBeString();
    actuarImpersonado($this, $token, 'GET', '/user/profile')->assertOk();

    actuarImpersonado($this, $token, 'POST', '/admin/impersonate/stop')->assertOk();
    actuarImpersonado($this, $token, 'GET', '/user/profile')->assertUnauthorized();
});
