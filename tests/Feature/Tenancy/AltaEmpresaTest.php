<?php

use App\Contracts\TenantOnboardingServiceInterface;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->enableTenancy();
    config(['multi-tenancy.onboarding.signup.enabled' => true]);
    $this->user = User::factory()->create();
    $this->headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->user)];
});

test('un usuario autenticado crea su empresa y queda como dueño activo', function () {
    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'mi-empresa')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.role', 'owner');

    $tenant = Tenant::where('slug', 'mi-empresa')->firstOrFail();
    expect($tenant->hasActiveMember($this->user))->toBeTrue()
        ->and($tenant->users()->first()->pivot->role)->toBe('owner')
        ->and($this->user->fresh()->hasRole('tenant-owner'))->toBeTrue();
});

test('el dueño recién creado puede listar los miembros de su empresa', function () {
    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)->assertCreated();

    $this->getJson('/tenant/members', $this->headers + ['X-Tenant' => 'mi-empresa'])
        ->assertOk()
        ->assertJsonPath('data.0.email', $this->user->email)
        ->assertJsonPath('data.0.role', 'owner');
});

test('sin autenticar el alta responde 401', function () {
    $this->postJson('/tenants', ['name' => 'X', 'slug' => 'xx-corp'])->assertUnauthorized();
});

test('con multiempresa apagado las rutas de alta responden 404', function () {
    config(['multi-tenancy.enabled' => false]);

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)->assertNotFound();
    $this->getJson('/tenant/members', $this->headers)->assertNotFound();
    $this->postJson('/tenant/invitations/accept', ['token' => 'x'], $this->headers)->assertNotFound();
});

test('con el alta libre apagada responde 403 con code estable', function () {
    config(['multi-tenancy.onboarding.signup.enabled' => false]);

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'tenant_signup_disabled');

    expect(Tenant::count())->toBe(0);
});

test('con revisión activada la empresa queda pendiente y no se puede usar hasta aprobarla', function () {
    config(['multi-tenancy.onboarding.signup.require_approval' => true]);

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->getJson('/tenant/members', $this->headers + ['X-Tenant' => 'mi-empresa'])->assertForbidden();

    $this->artisan('tenancy:approve', ['tenant' => 'mi-empresa'])->assertSuccessful();
    expect(Tenant::where('slug', 'mi-empresa')->first()->status)->toBe(TenantStatus::Active);

    $this->getJson('/tenant/members', $this->headers + ['X-Tenant' => 'mi-empresa'])->assertOk();
});

test('el trial opcional fija trial_ends_at', function () {
    config(['multi-tenancy.onboarding.signup.trial_days' => 14]);

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)->assertCreated();

    $tenant = Tenant::where('slug', 'mi-empresa')->firstOrFail();
    expect((int) round(now()->diffInDays($tenant->trial_ends_at, true)))->toBeIn([13, 14]);
});

test('el slug duplicado, reservado, numérico o mal formado responde 422', function (string $slug) {
    $this->makeTenant('ocupado');

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => $slug], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
})->with(['ocupado', 'admin', '12345', 'Con Mayus', 'ab', '-guion', 'doble--guion']);

test('un tenant ya existente borrado lógicamente sigue ocupando el slug (422, no 500)', function () {
    $this->makeTenant('fantasma')->delete();

    $this->postJson('/tenants', ['name' => 'Otra', 'slug' => 'fantasma'], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
});

test('el tope de empresas por usuario responde 422 tenant_limit_reached', function () {
    config(['multi-tenancy.onboarding.signup.max_tenants_per_user' => 1]);

    $this->postJson('/tenants', ['name' => 'Una', 'slug' => 'una-corp'], $this->headers)->assertCreated();
    $this->postJson('/tenants', ['name' => 'Dos', 'slug' => 'dos-corp'], $this->headers)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tenant_limit_reached');
});

test('el tenant del cuerpo se ignora: solo se crea la empresa pedida y el usuario no entra en otras', function () {
    $otra = $this->makeTenant('otra-corp');

    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa', 'tenant_id' => $otra->id, 'role' => 'member'], $this->headers)
        ->assertCreated();

    expect($otra->hasActiveMember($this->user))->toBeFalse();
});

test('mine lista solo las empresas del usuario con su rol', function () {
    $this->postJson('/tenants', ['name' => 'Mi Empresa', 'slug' => 'mi-empresa'], $this->headers)->assertCreated();
    $this->makeTenant('ajena');

    $this->getJson('/tenants/mine', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'mi-empresa')
        ->assertJsonPath('data.0.role', 'owner');
});

test('si falla la asignación del rol no queda ni tenant ni membresía (transacción única)', function () {
    // Fuerza el fallo de la membresía dentro de la transacción: el usuario ya no existe para la FK.
    $ghost = new User(['name' => 'x']);
    $ghost->id = 999999;

    expect(fn () => app(TenantOnboardingServiceInterface::class)->createTenant($ghost, ['name' => 'Zz', 'slug' => 'zz-corp']))
        ->toThrow(QueryException::class);

    expect(Tenant::where('slug', 'zz-corp')->exists())->toBeFalse();
});
