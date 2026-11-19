<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');

    // Quien gestiona es admin DE ESTE tenant (tenant_user.role): el permiso suelto no basta (docs/tenant-onboarding.md).
    $this->adminA = User::factory()->create();
    $this->adminA->assignRole(Role::where('name', 'user')->first());
    $this->a->users()->attach($this->adminA->getKey(), ['status' => 'active', 'role' => 'admin', 'joined_at' => now()]);
    TenantRoles::sync($this->adminA, $this->a, 'admin');

    $this->miembroA = User::factory()->create();
    $this->miembroA->assignRole(Role::where('name', 'user')->first());
    $this->addMember($this->a, $this->miembroA);

    $this->headersA = fn (User $u) => ['Authorization' => 'Bearer '.JWTAuth::fromUser($u), 'X-Tenant' => 'acme'];
});

test('quien tiene el permiso actualiza idioma, zona horaria y moneda de su tenant', function () {
    $this->putJson('/tenant/locale', ['locale' => 'en', 'timezone' => 'America/Bogota', 'currency' => 'COP'], ($this->headersA)($this->adminA))
        ->assertOk()
        ->assertJsonPath('data.stored.currency', 'COP')
        ->assertJsonPath('data.effective.currency', 'COP')
        ->assertJsonPath('data.effective.timezone', 'America/Bogota');

    expect($this->a->refresh()->currency)->toBe('COP')->and($this->a->locale)->toBe('en');
});

test('un miembro sin permiso puede ver pero no cambiar los ajustes del tenant', function () {
    $this->getJson('/tenant/locale', ($this->headersA)($this->miembroA))->assertOk();
    $this->putJson('/tenant/locale', ['currency' => 'EUR'], ($this->headersA)($this->miembroA))
        ->assertForbidden();

    expect($this->a->refresh()->currency)->toBeNull();
});

test('los ajustes inválidos se rechazan con 422', function () {
    $h = ($this->headersA)($this->adminA);

    $this->putJson('/tenant/locale', ['timezone' => 'Mordor/Barad-dur'], $h)->assertStatus(422)->assertJsonValidationErrors('timezone');
    $this->putJson('/tenant/locale', ['currency' => 'XXX'], $h)->assertStatus(422)->assertJsonValidationErrors('currency');
    $this->putJson('/tenant/locale', ['currency' => 'usd'], $h)->assertStatus(422)->assertJsonValidationErrors('currency');
    $this->putJson('/tenant/locale', ['locale' => 'fr'], $h)->assertStatus(422)->assertJsonValidationErrors('locale');
});

test('la moneda se valida contra config/currencies.php y I18N_CURRENCIES la restringe', function () {
    $h = ($this->headersA)($this->adminA);

    // Sin restricción vale cualquier moneda que Money sepa manejar (KWD: 3 decimales).
    $this->putJson('/tenant/locale', ['currency' => 'KWD'], $h)->assertOk();

    config(['i18n.currencies' => ['PEN', 'USD', 'ZZZ']]);
    $this->putJson('/tenant/locale', ['currency' => 'PEN'], $h)->assertOk();
    $this->putJson('/tenant/locale', ['currency' => 'EUR'], $h)->assertStatus(422)->assertJsonValidationErrors('currency');
    // Una moneda que Money no conoce no se acepta aunque esté en la lista.
    $this->putJson('/tenant/locale', ['currency' => 'ZZZ'], $h)->assertStatus(422)->assertJsonValidationErrors('currency');
});

test('el rol de admin en A no permite cambiar los ajustes de B a quien allí es miembro raso', function () {
    $this->addMember($this->b, $this->adminA); // rol `member` en B; con equipos apagados su rol de Spatie es global
    $h = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->adminA), 'X-Tenant' => 'beta'];

    $this->putJson('/tenant/locale', ['locale' => 'en'], $h)->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');
    expect($this->b->refresh()->locale)->toBeNull();
});

test('el tenant A no puede tocar los ajustes del tenant B', function () {
    $this->b->update(['currency' => 'MXN']);

    // Miembro de A pidiendo B: no es miembro, 403
    $this->putJson('/tenant/locale', ['currency' => 'EUR'], ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->adminA), 'X-Tenant' => 'beta'])
        ->assertForbidden();

    // Y lo que cambia en A no altera B
    $this->putJson('/tenant/locale', ['currency' => 'EUR'], ($this->headersA)($this->adminA))->assertOk();

    expect($this->b->refresh()->currency)->toBe('MXN')->and($this->a->refresh()->currency)->toBe('EUR');
});

test('sin tenant en la petición no hay ajustes de tenant', function () {
    $this->getJson('/tenant/locale', ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->adminA)])
        ->assertStatus(400);
});

test('sin sesión se responde 401', function () {
    $this->getJson('/tenant/locale', ['X-Tenant' => 'acme'])->assertUnauthorized();
});
