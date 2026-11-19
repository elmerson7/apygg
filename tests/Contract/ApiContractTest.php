<?php

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\OpenApiValidator;

/*
 * Contrato real: cada respuesta se valida contra docs/openapi.json (OpenAPI 3.1) con
 * `assertMatchesOpenApi()`. Si falla, o la respuesta se salió del contrato (arregla el código) o el
 * contrato quedó viejo (arregla el controller/Resource y corre `make openapi`).
 * Ver docs/api-contract.md.
 */

uses(RefreshDatabase::class);

function contratoBearer(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

// ── salud ───────────────────────────────────────────────────────────────────

test('los health checks cumplen el contrato', function (string $uri) {
    $this->getJson($uri)->assertSuccessful();

    $this->assertMatchesOpenApi($this->getJson($uri));
})->with(['/health', '/health/live', '/health/ready']);

test('el health detallado cumple el contrato', function () {
    $this->assertMatchesOpenApi(
        $this->withHeaders(contratoBearer(User::factory()->create()))->getJson('/health/detailed')
    );
});

// ── autenticación ───────────────────────────────────────────────────────────

test('registro, login, me, refresh y logout cumplen el contrato', function () {
    $this->assertMatchesOpenApi($registro = $this->postJson('/auth/register', [
        'name' => 'Contrato Prueba',
        'email' => 'contrato@example.com',
        'password' => 'Sup3r-Secreta-2026!',
        'password_confirmation' => 'Sup3r-Secreta-2026!',
    ]));
    expect($registro->status())->toBe(201);

    $this->assertMatchesOpenApi($login = $this->postJson('/auth/login', [
        'login' => 'contrato@example.com',
        'password' => 'Sup3r-Secreta-2026!',
    ]));
    $login->assertOk();

    $token = $login->json('data.access_token');
    $refresh = $login->json('data.refresh_token');

    $this->assertMatchesOpenApi($this->withToken($token)->getJson('/auth/me')->assertOk());
    $this->assertMatchesOpenApi($this->postJson('/auth/refresh', ['refresh_token' => $refresh])->assertOk());
    $this->assertMatchesOpenApi($this->withToken($token)->postJson('/auth/logout')->assertOk());
});

// ── usuarios: listado paginado y detalle ────────────────────────────────────

test('el listado paginado de usuarios cumple el contrato', function () {
    User::factory()->count(3)->create();

    $response = $this->withHeaders(contratoBearer($this->createAdmin()))->getJson('/users?per_page=2')->assertOk();

    $this->assertMatchesOpenApi($response);
    expect($response->json('meta.per_page'))->toBe(2)->and($response->json('data'))->toHaveCount(2);
});

test('el detalle de un usuario cumple el contrato', function () {
    $admin = $this->createAdmin();

    $this->assertMatchesOpenApi(
        $this->withHeaders(contratoBearer($admin))->getJson("/users/{$admin->id}")->assertOk()
    );
});

// ── crear y actualizar recursos ─────────────────────────────────────────────

test('crear y actualizar un setting cumple el contrato', function () {
    $headers = contratoBearer($this->createAdmin());

    $this->assertMatchesOpenApi($crear = $this->withHeaders($headers)->postJson('/settings', [
        'key' => 'contrato.prueba', 'value' => 'uno', 'type' => 'string', 'group' => 'contrato',
    ]));
    expect($crear->status())->toBe(201);

    $this->assertMatchesOpenApi(
        $this->withHeaders($headers)->putJson('/settings/'.$crear->json('data.id'), ['value' => 'dos'])->assertOk()
    );

    $this->assertMatchesOpenApi($this->withHeaders($headers)->getJson('/settings')->assertOk());
});

test('crear, actualizar y listar webhooks cumple el contrato', function () {
    $headers = contratoBearer(User::factory()->create());

    $this->assertMatchesOpenApi($crear = $this->withHeaders($headers)->postJson('/webhooks', [
        'name' => 'Contrato', 'url' => 'https://example.com/hook',
    ]));
    expect($crear->status())->toBe(201);

    $id = $crear->json('data.id');
    $this->assertMatchesOpenApi($this->withHeaders($headers)->putJson("/webhooks/{$id}", ['name' => 'Contrato 2'])->assertOk());
    $this->assertMatchesOpenApi($this->withHeaders($headers)->getJson("/webhooks/{$id}")->assertOk());
    $this->assertMatchesOpenApi($this->withHeaders($headers)->getJson('/webhooks')->assertOk());
});

// ── errores: siempre con `code` y `request_id` ──────────────────────────────

test('un 401 sin token cumple el contrato de error', function () {
    $response = $this->getJson('/users')->assertUnauthorized();

    $this->assertMatchesOpenApi($response);
    expect($response->json('code'))->toBeString()->and($response->json('request_id'))->toBeString();
});

test('un 401 por credenciales inválidas cumple el contrato de error', function () {
    $response = $this->postJson('/auth/login', ['login' => 'nadie@example.com', 'password' => 'incorrecta-123'])->assertUnauthorized();

    $this->assertMatchesOpenApi($response);
    expect($response->json('code'))->toBeString();
});

test('un 403 por falta de permiso cumple el contrato de error', function () {
    $response = $this->withHeaders(contratoBearer(User::factory()->create()))->getJson('/users')->assertForbidden();

    $this->assertMatchesOpenApi($response);
    expect($response->json('code'))->toBe('forbidden');
});

test('un 404 de recurso inexistente cumple el contrato de error', function () {
    $response = $this->withHeaders(contratoBearer($this->createAdmin()))->getJson('/users/999999')->assertNotFound();

    $this->assertMatchesOpenApi($response);
    expect($response->json('code'))->toBe('not_found');
});

test('un 404 de un recurso ajeno de webhooks cumple el contrato de error', function () {
    $ajeno = Webhook::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->assertMatchesOpenApi(
        $this->withHeaders(contratoBearer(User::factory()->create()))->getJson("/webhooks/{$ajeno->id}")->assertNotFound()
    );
});

test('un 422 de validación cumple el contrato de error con errors por campo', function () {
    $response = $this->postJson('/auth/login', [])->assertUnprocessable();

    $this->assertMatchesOpenApi($response);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('request_id'))->toBeString()
        ->and($response->json('errors.login'))->toBeArray();
});

test('el validador detecta una respuesta que se sale del contrato', function () {
    $response = $this->withHeaders(contratoBearer($this->createAdmin()))->getJson('/users')->assertOk();
    $roto = $response->baseResponse->setContent((string) json_encode(['success' => true, 'data' => 'no-es-array']));

    $problemas = OpenApiValidator::problems(new TestResponse($roto));

    expect($problemas)->not->toBeEmpty();
});
