<?php

use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /settings. El acceso lo decide SettingsPolicy: solo el rol admin.
 * Cada endpoint se prueba con camino feliz y con el camino denegado
 * (sin token 401, sin rol 403, datos inválidos 422, recurso inexistente 404).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function settingsAdminHeaders(): array
{
    $admin = User::factory()->admin()->create();

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($admin)];
}

function settingsUserHeaders(): array
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'user')->value('id'));

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function settingsCrear(array $overrides = []): Settings
{
    return Settings::factory()->create(array_merge([
        'key' => 'app.color',
        'type' => 'string',
        'value' => 'rojo',
        'group' => 'app',
        'is_public' => false,
        'is_encrypted' => false,
    ], $overrides));
}

// ── index ───────────────────────────────────────────────────────────────────

test('un administrador lista los settings paginados', function () {
    settingsCrear(['key' => 'app.uno']);
    settingsCrear(['key' => 'app.dos']);

    $this->withHeaders(settingsAdminHeaders())->getJson('/settings')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
        ->assertJsonPath('meta.total', 2);
});

test('el listado de settings filtra por grupo', function () {
    settingsCrear(['key' => 'app.uno', 'group' => 'app']);
    settingsCrear(['key' => 'pago.uno', 'group' => 'payment']);

    $this->withHeaders(settingsAdminHeaders())->getJson('/settings?group=payment')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.key', 'pago.uno');
});

test('un usuario sin rol de administrador no lista los settings', function () {
    settingsCrear();

    $this->withHeaders(settingsUserHeaders())->getJson('/settings')
        ->assertForbidden()
        ->assertJsonPath('status', 403);
});

test('listar settings sin token responde 401', function () {
    $this->getJson('/settings')->assertUnauthorized();
});

// ── show ────────────────────────────────────────────────────────────────────

test('un administrador ve un setting por id', function () {
    $setting = settingsCrear();

    $this->withHeaders(settingsAdminHeaders())->getJson("/settings/{$setting->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'app.color')
        ->assertJsonPath('data.value', 'rojo');
});

test('un usuario sin rol de administrador no ve un setting por id', function () {
    $setting = settingsCrear();

    $this->withHeaders(settingsUserHeaders())->getJson("/settings/{$setting->id}")
        ->assertForbidden();
});

test('pedir un setting inexistente por id responde 404', function () {
    $this->withHeaders(settingsAdminHeaders())->getJson('/settings/999999')
        ->assertNotFound()
        ->assertJsonPath('status', 404);
});

test('ver un setting sin token responde 401', function () {
    $setting = settingsCrear();

    $this->getJson("/settings/{$setting->id}")->assertUnauthorized();
});

// ── getByKey ────────────────────────────────────────────────────────────────

test('un administrador obtiene un setting por su clave', function () {
    settingsCrear(['key' => 'app.nombre', 'value' => 'APYGG']);

    $this->withHeaders(settingsAdminHeaders())->getJson('/settings/key/app.nombre')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'app.nombre')
        ->assertJsonPath('data.value', 'APYGG');
});

test('pedir por clave un setting que no existe responde 404', function () {
    $this->withHeaders(settingsAdminHeaders())->getJson('/settings/key/no.existe')
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

test('un usuario sin rol de administrador no obtiene un setting por clave', function () {
    settingsCrear(['key' => 'app.nombre']);

    $this->withHeaders(settingsUserHeaders())->getJson('/settings/key/app.nombre')
        ->assertForbidden();
});

test('obtener por clave sin token responde 401', function () {
    settingsCrear(['key' => 'app.nombre']);

    $this->getJson('/settings/key/app.nombre')->assertUnauthorized();
});

test('tras actualizar un setting, la lectura por clave devuelve el valor nuevo', function () {
    $setting = settingsCrear(['key' => 'app.tema', 'value' => 'claro']);
    $headers = settingsAdminHeaders();

    // Primera lectura: deja el valor en caché
    $this->withHeaders($headers)->getJson('/settings/key/app.tema')->assertJsonPath('data.value', 'claro');

    $this->withHeaders($headers)->putJson("/settings/{$setting->id}", ['value' => 'oscuro'])->assertOk();

    $this->withHeaders($headers)->getJson('/settings/key/app.tema')
        ->assertOk()
        ->assertJsonPath('data.value', 'oscuro');
});

// ── store ───────────────────────────────────────────────────────────────────

test('un administrador crea un setting y queda en la base de datos', function () {
    $this->withHeaders(settingsAdminHeaders())->postJson('/settings', [
        'key' => 'app.idioma',
        'value' => 'es',
        'type' => 'string',
        'group' => 'app',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'app.idioma');

    $this->assertDatabaseHas('settings', ['key' => 'app.idioma', 'group' => 'app']);
});

test('crear un setting sin token responde 401 y no lo guarda', function () {
    $this->postJson('/settings', ['key' => 'app.idioma', 'value' => 'es', 'type' => 'string'])
        ->assertUnauthorized();

    $this->assertDatabaseMissing('settings', ['key' => 'app.idioma']);
});

test('crear un setting con una clave que ya existe responde 422', function () {
    settingsCrear(['key' => 'app.repetido']);

    $this->withHeaders(settingsAdminHeaders())->postJson('/settings', [
        'key' => 'app.repetido',
        'value' => 'otro',
        'type' => 'string',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect(Settings::where('key', 'app.repetido')->count())->toBe(1);
});

test('crear un setting sin clave ni tipo responde 422', function () {
    $this->withHeaders(settingsAdminHeaders())->postJson('/settings', ['value' => 'algo'])
        ->assertUnprocessable();
});

test('crear un setting con una clave con caracteres no permitidos responde 422', function () {
    $this->withHeaders(settingsAdminHeaders())->postJson('/settings', [
        'key' => 'app nombre con espacios',
        'value' => 'algo',
        'type' => 'string',
    ])->assertUnprocessable();
});

test('crear un setting encriptado guarda el valor cifrado y la API lo devuelve en claro', function () {
    $headers = settingsAdminHeaders();

    $this->withHeaders($headers)->postJson('/settings', [
        'key' => 'integracion.token',
        'value' => 'valor-secreto-123',
        'type' => 'string',
        'is_encrypted' => true,
    ])->assertCreated();

    $guardado = Settings::where('key', 'integracion.token')->firstOrFail();

    expect($guardado->value)->not->toBe('valor-secreto-123');

    $this->withHeaders($headers)->getJson("/settings/{$guardado->id}")
        ->assertOk()
        ->assertJsonPath('data.value', 'valor-secreto-123');
});

// ── update ──────────────────────────────────────────────────────────────────

test('un administrador actualiza un setting y el cambio queda en la base de datos', function () {
    $setting = settingsCrear(['key' => 'app.color', 'value' => 'rojo']);

    $this->withHeaders(settingsAdminHeaders())->putJson("/settings/{$setting->id}", ['value' => 'verde'])
        ->assertOk()
        ->assertJsonPath('data.value', 'verde');

    $this->assertDatabaseHas('settings', ['id' => $setting->id, 'value' => 'verde']);
});

test('actualizar un setting sin token responde 401 y no lo cambia', function () {
    $setting = settingsCrear(['value' => 'rojo']);

    $this->putJson("/settings/{$setting->id}", ['value' => 'verde'])->assertUnauthorized();

    $this->assertDatabaseHas('settings', ['id' => $setting->id, 'value' => 'rojo']);
});

test('actualizar un setting con un valor que no encaja con su tipo responde 422', function () {
    $setting = settingsCrear(['type' => 'integer', 'value' => '5']);

    $this->withHeaders(settingsAdminHeaders())->putJson("/settings/{$setting->id}", [
        'type' => 'integer',
        'value' => 'no-numerico',
    ])->assertUnprocessable();

    $this->assertDatabaseHas('settings', ['id' => $setting->id, 'value' => '5']);
});

test('actualizar un setting inexistente responde 404', function () {
    $this->withHeaders(settingsAdminHeaders())->putJson('/settings/999999', ['value' => 'x'])
        ->assertNotFound();
});

// ── destroy ─────────────────────────────────────────────────────────────────

test('un administrador elimina un setting (borrado lógico)', function () {
    $setting = settingsCrear();

    $this->withHeaders(settingsAdminHeaders())->deleteJson("/settings/{$setting->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('settings', ['id' => $setting->id]);
});

test('un usuario sin rol de administrador no elimina un setting', function () {
    $setting = settingsCrear();

    $this->withHeaders(settingsUserHeaders())->deleteJson("/settings/{$setting->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('settings', ['id' => $setting->id, 'deleted_at' => null]);
});

test('eliminar un setting sin token responde 401', function () {
    $setting = settingsCrear();

    $this->deleteJson("/settings/{$setting->id}")->assertUnauthorized();

    $this->assertDatabaseHas('settings', ['id' => $setting->id, 'deleted_at' => null]);
});

test('eliminar un setting inexistente responde 404', function () {
    $this->withHeaders(settingsAdminHeaders())->deleteJson('/settings/999999')
        ->assertNotFound();
});

test('un usuario sin permiso recibe 403 también para un id que no existe, sin revelar si existe', function () {
    $this->withHeaders(settingsUserHeaders())->getJson('/settings/999999')->assertForbidden();
    $this->withHeaders(settingsUserHeaders())->putJson('/settings/999999', ['value' => 'x'])->assertForbidden();
    $this->withHeaders(settingsUserHeaders())->deleteJson('/settings/999999')->assertForbidden();
});
