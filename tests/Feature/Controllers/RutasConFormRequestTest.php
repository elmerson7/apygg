<?php

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use App\Models\Webhook;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function cabecerasDe(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function usuarioNormal(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'user')->value('id'));

    return $user;
}

// ── Webhooks ────────────────────────────────────────────────────────────────

test('un usuario autenticado crea un webhook propio', function () {
    $user = usuarioNormal();

    $this->withHeaders(cabecerasDe($user))->postJson('/webhooks', [
        'name' => 'Mi webhook',
        'url' => 'https://example.com/hook',
        'events' => ['user.created'],
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Mi webhook');

    expect(Webhook::where('user_id', $user->id)->count())->toBe(1);
});

test('crear un webhook con datos invalidos responde 422', function () {
    $this->withHeaders(cabecerasDe(usuarioNormal()))
        ->postJson('/webhooks', ['name' => 'x', 'url' => 'no-es-url'])
        ->assertUnprocessable();
});

test('crear un webhook sin autenticar responde 401', function () {
    $this->postJson('/webhooks', ['name' => 'Mi webhook', 'url' => 'https://example.com/hook'])
        ->assertUnauthorized();
});

test('un usuario actualiza su webhook', function () {
    $user = usuarioNormal();
    $webhook = Webhook::factory()->create(['user_id' => $user->id]);

    $this->withHeaders(cabecerasDe($user))
        ->putJson("/webhooks/{$webhook->id}", ['name' => 'Renombrado'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renombrado');

    expect($webhook->fresh()->name)->toBe('Renombrado');
});

test('un usuario no puede actualizar el webhook de otro', function () {
    $webhook = Webhook::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Ajeno']);

    $this->withHeaders(cabecerasDe(usuarioNormal()))
        ->putJson("/webhooks/{$webhook->id}", ['name' => 'Robado'])
        ->assertNotFound();

    expect($webhook->fresh()->name)->toBe('Ajeno');
});

// ── API keys ────────────────────────────────────────────────────────────────

test('un usuario autenticado crea una api key y recibe la clave una sola vez', function () {
    $user = usuarioNormal();

    $this->withHeaders(cabecerasDe($user))->postJson('/api-keys', [
        'name' => 'Integracion',
        'scopes' => ['users.read'],
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['api_key', 'key', 'warning']]);

    expect(ApiKey::where('user_id', $user->id)->count())->toBe(1);
});

test('crear una api key con datos invalidos responde 422', function () {
    $this->withHeaders(cabecerasDe(usuarioNormal()))
        ->postJson('/api-keys', ['name' => 'x', 'scopes' => ['formato malo']])
        ->assertUnprocessable();
});

test('crear una api key sin autenticar responde 401', function () {
    $this->postJson('/api-keys', ['name' => 'Integracion'])->assertUnauthorized();
});

// ── Settings ────────────────────────────────────────────────────────────────

test('un administrador crea un setting', function () {
    $admin = User::factory()->admin()->create();

    $this->withHeaders(cabecerasDe($admin))->postJson('/settings', [
        'key' => 'app.nombre',
        'value' => 'APYGG',
        'type' => 'string',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect(Settings::where('key', 'app.nombre')->exists())->toBeTrue();
});

test('un usuario sin rol de administrador no puede crear un setting', function () {
    $this->withHeaders(cabecerasDe(usuarioNormal()))->postJson('/settings', [
        'key' => 'app.nombre',
        'value' => 'APYGG',
        'type' => 'string',
    ])->assertForbidden();

    expect(Settings::where('key', 'app.nombre')->exists())->toBeFalse();
});

test('crear un setting con tipo incoherente responde 422', function () {
    $admin = User::factory()->admin()->create();

    $this->withHeaders(cabecerasDe($admin))->postJson('/settings', [
        'key' => 'app.numero',
        'value' => 'no-numerico',
        'type' => 'integer',
    ])->assertUnprocessable();
});

test('un administrador actualiza un setting', function () {
    $admin = User::factory()->admin()->create();
    $setting = Settings::factory()->create(['key' => 'app.color', 'type' => 'string', 'value' => 'rojo', 'is_encrypted' => false]);

    $this->withHeaders(cabecerasDe($admin))
        ->putJson("/settings/{$setting->id}", ['value' => 'azul'])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($setting->fresh()->value)->toBe('azul');
});

test('un usuario sin rol de administrador no puede actualizar un setting', function () {
    $setting = Settings::factory()->create(['key' => 'app.color', 'type' => 'string', 'value' => 'rojo', 'is_encrypted' => false]);

    $this->withHeaders(cabecerasDe(usuarioNormal()))
        ->putJson("/settings/{$setting->id}", ['value' => 'azul'])
        ->assertForbidden();

    expect($setting->fresh()->value)->toBe('rojo');
});
