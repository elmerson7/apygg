<?php

use App\Contracts\SettingsRegistryInterface;
use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);
    $this->registry = app(SettingsRegistryInterface::class);
    $this->actor = User::factory()->create();
});

function ajMiembro($test, $tenant, array $permisos = []): array
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());
    // Escribir ajustes exige ser owner/admin DE ESE tenant (tenant_user.role), no solo el permiso (docs/tenant-onboarding.md).
    $rol = in_array('tenant-settings.manage', $permisos, true) ? 'admin' : 'member';
    $tenant->users()->attach($user->getKey(), ['status' => 'active', 'role' => $rol, 'joined_at' => now()]);
    foreach ($permisos as $p) {
        $user->givePermissionTo($p);
    }

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $tenant->id];
}

function ajAdmin(): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->admin()->create())];
}

test('resuelve tenant, luego plataforma y luego el default', function () {
    // default
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('auto'));

    // plataforma
    $this->registry->setPlatform('general.theme', 'dark');
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('dark'));

    // tenant gana sobre plataforma; B sigue en plataforma
    $this->ctx->run($this->a, fn () => $this->registry->setTenant($this->actor, 'general.theme', 'light'));
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('light'));
    $this->ctx->run($this->b, fn () => expect($this->registry->get('general.theme'))->toBe('dark'));

    // reset del tenant vuelve a plataforma; reset de plataforma, al default
    $this->ctx->run($this->a, fn () => $this->registry->resetTenant($this->actor, 'general.theme'));
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('dark'));
    $this->registry->resetPlatform('general.theme');
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('auto'));
});

test('valida cada tipo con 422 y code estable', function (string $key, mixed $valor) {
    $this->asHeaders(ajAdmin())->putJson("/settings/registry/{$key}", ['value' => $valor])
        ->assertStatus(422)->assertJsonPath('code', 'setting_invalid_value');
})->with([
    'bool' => ['security.allow_public_sharing', 'quizás'],
    'int fuera de rango' => ['security.session_timeout_minutes', 2],
    'int no entero' => ['security.session_timeout_minutes', 'abc'],
    'enum' => ['general.theme', 'rosa'],
    'string con regla' => ['general.support_email', 'no-es-correo'],
    'json' => ['ui.branding', 'texto'],
]);

test('guarda cada tipo con su tipo PHP exacto', function () {
    $this->registry->setPlatform('security.allow_public_sharing', true);
    $this->registry->setPlatform('security.session_timeout_minutes', '90');
    $this->registry->setPlatform('ui.branding', ['color' => 'rojo']);

    expect($this->registry->get('security.allow_public_sharing'))->toBeTrue()
        ->and($this->registry->get('security.session_timeout_minutes'))->toBe(90)
        ->and($this->registry->get('ui.branding'))->toBe(['color' => 'rojo']);
});

test('una key no declarada es 404 setting_unknown', function () {
    $this->asHeaders(ajAdmin())->putJson('/settings/registry/no.existe', ['value' => 1])
        ->assertNotFound()->assertJsonPath('code', 'setting_unknown');
});

test('un ajuste solo de plataforma no lo escribe el tenant', function () {
    $h = ajMiembro($this, $this->a, ['tenant-settings.manage']);

    $this->asHeaders($h)->putJson('/tenant/settings/system.maintenance_message', ['value' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'setting_not_editable');
});

test('el CRUD genérico de settings valida las keys declaradas', function () {
    $this->asHeaders(ajAdmin())->postJson('/settings', ['key' => 'general.theme', 'value' => 'rosa', 'type' => 'string'])
        ->assertStatus(422)->assertJsonPath('code', 'setting_invalid_value');

    $this->asHeaders(ajAdmin())->postJson('/settings', ['key' => 'security.session_timeout_minutes', 'value' => '30', 'type' => 'string'])
        ->assertCreated();
    expect(Settings::where('key', 'security.session_timeout_minutes')->value('type'))->toBe('integer');
});

test('el tenant escribe y lee sus ajustes por la API y el tenant B no los ve', function () {
    $ha = ajMiembro($this, $this->a, ['tenant-settings.manage', 'tenant-settings.read']);
    $hb = ajMiembro($this, $this->b, ['tenant-settings.manage', 'tenant-settings.read']);

    $this->asHeaders($ha)->putJson('/tenant/settings/general.theme', ['value' => 'dark'])->assertOk();

    $valor = fn ($resp) => collect($resp->json('data'))->firstWhere('key', 'general.theme');

    expect($valor($this->asHeaders($ha)->getJson('/tenant/settings'))['value'])->toBe('dark')
        ->and($valor($this->asHeaders($ha)->getJson('/tenant/settings'))['source'])->toBe('tenant')
        ->and($valor($this->asHeaders($hb)->getJson('/tenant/settings'))['value'])->toBe('auto');

    // B borrando "el mismo" ajuste no toca el de A
    $this->asHeaders($hb)->deleteJson('/tenant/settings/general.theme')->assertOk();
    expect($valor($this->asHeaders($ha)->getJson('/tenant/settings'))['value'])->toBe('dark');
});

test('sin permiso o sin tenant los endpoints de tenant se deniegan', function () {
    $sin = ajMiembro($this, $this->a);
    $this->asHeaders($sin)->getJson('/tenant/settings')->assertForbidden();
    $this->asHeaders($sin)->putJson('/tenant/settings/general.theme', ['value' => 'dark'])->assertForbidden();

    $lector = ajMiembro($this, $this->a, ['tenant-settings.read']);
    $this->asHeaders($lector)->putJson('/tenant/settings/general.theme', ['value' => 'dark'])->assertForbidden();

    $user = User::factory()->create();
    $user->givePermissionTo('tenant-settings.read');
    $this->asHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])->getJson('/tenant/settings')
        ->assertStatus(400)->assertJsonPath('code', 'tenant_required');

    $this->asHeaders([])->getJson('/tenant/settings')->assertUnauthorized();
});

test('el rol de admin en A no permite escribir ajustes en B a quien allí es miembro raso', function () {
    $user = User::factory()->create();
    $this->a->users()->attach($user->getKey(), ['status' => 'active', 'role' => 'admin', 'joined_at' => now()]);
    TenantRoles::sync($user, $this->a, 'admin'); // con equipos apagados, rol de Spatie global
    $this->addMember($this->b, $user);           // raso en B

    $this->asHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $this->b->id])
        ->putJson('/tenant/settings/general.theme', ['value' => 'dark'])
        ->assertForbidden()->assertJsonPath('code', 'tenant_role_insufficient');

    expect($this->b->refresh()->settings['general.theme'] ?? null)->toBeNull();
});

test('los endpoints de plataforma exigen system.settings', function () {
    $h = ajMiembro($this, $this->a);
    unset($h['X-Tenant']);

    $this->asHeaders($h)->getJson('/settings/registry')->assertForbidden();
    $this->asHeaders(ajAdmin())->getJson('/settings/registry')->assertOk()
        ->assertJsonFragment(['key' => 'general.theme']);
});

test('la caché del tenant se invalida al escribir', function () {
    $this->ctx->run($this->a, function () {
        expect($this->registry->get('general.theme'))->toBe('auto'); // llena la caché
        $this->registry->setTenant($this->actor, 'general.theme', 'dark');
        expect($this->registry->get('general.theme'))->toBe('dark');
        $this->registry->resetTenant($this->actor, 'general.theme');
        expect($this->registry->get('general.theme'))->toBe('auto');
    });
});

test('escribir un ajuste de plataforma invalida lo que ve un tenant', function () {
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('auto'));
    $this->registry->setPlatform('general.theme', 'light');
    $this->ctx->run($this->a, fn () => expect($this->registry->get('general.theme'))->toBe('light'));
});
