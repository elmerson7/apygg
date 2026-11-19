<?php

use App\Models\Llm\LlmArea;
use App\Models\Logs\LlmAuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /admin/llm. Gestión de áreas: permiso llm.manage.
 * Auditoría (logs y estadísticas): permiso llm.logs, de solo lectura.
 * Ningún test llama a un proveedor de verdad: el gateway se prueba con Http::fake.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('llm.url', 'https://llm.ejemplo.test/v1');
    config()->set('llm.api_key', 'clave-de-prueba');
    config()->set('llm.defaults', ['timeout' => 5, 'max_attempts' => 1, 'max_iterations' => 1]);
});

function llmCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function llmUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();

    foreach ($permisos as $permiso) {
        $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));
    }

    return $user;
}

function llmArea(array $extra = []): LlmArea
{
    return LlmArea::create(array_merge([
        'key' => 'soporte',
        'name' => 'Soporte',
        'primary_provider' => 'openai',
        'primary_model' => 'modelo-principal',
        'fallbacks' => [],
        'is_active' => true,
        'sort_order' => 0,
    ], $extra));
}

function llmRegistro(array $extra = []): LlmAuditLog
{
    return LlmAuditLog::create(array_merge([
        'use_case' => 'soporte.clasificar',
        'module' => 'tests',
        'model' => 'modelo-principal',
        'prompt_tokens' => 5,
        'completion_tokens' => 5,
        'total_tokens' => 10,
        'status' => LlmAuditLog::STATUS_SUCCESS,
    ], $extra));
}

function llmRespuestaFalsa(string $contenido): array
{
    return [
        'model' => 'modelo-principal',
        'choices' => [['message' => ['content' => $contenido], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ];
}

// ── status ──────────────────────────────────────────────────────────────────

test('quien gestiona la IA ve el estado de la capa con sus áreas', function () {
    llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->getJson('/admin/llm/status')
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.areas.0.key', 'soporte')
        ->assertJsonPath('data.areas.0.usable', true);
});

test('el estado de la IA no se muestra sin llm.manage', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/status')
        ->assertForbidden();
});

test('el estado de la IA sin token responde 401', function () {
    $this->getJson('/admin/llm/status')->assertUnauthorized();
});

// ── áreas: listado y detalle ────────────────────────────────────────────────

test('quien gestiona la IA lista las áreas paginadas', function () {
    llmArea(['key' => 'soporte']);
    llmArea(['key' => 'informes', 'name' => 'Informes', 'sort_order' => 1]);

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->getJson('/admin/llm/areas')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta'])
        ->assertJsonPath('meta.total', 2);
});

test('el listado de áreas no se ve con solo llm.logs', function () {
    llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/areas')
        ->assertForbidden();
});

test('el listado de áreas sin token responde 401', function () {
    $this->getJson('/admin/llm/areas')->assertUnauthorized();
});

test('un administrador ve un área por id', function () {
    $area = llmArea();

    $this->withHeaders(llmCabeceras(User::factory()->admin()->create()))->getJson("/admin/llm/areas/{$area->id}")
        ->assertOk()
        ->assertJsonPath('data.key', 'soporte');
});

test('pedir un área inexistente responde 404', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->getJson('/admin/llm/areas/999999')
        ->assertNotFound();
});

// ── áreas: alta ─────────────────────────────────────────────────────────────

test('quien gestiona la IA crea un área y queda guardada', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson('/admin/llm/areas', [
        'key' => 'facturas',
        'name' => 'Facturas',
        'primary_provider' => 'openai',
        'primary_model' => 'modelo-facturas',
        'fallbacks' => [['provider' => 'openai', 'model' => 'modelo-respaldo']],
        'is_active' => true,
    ])->assertStatus(201);

    $this->assertDatabaseHas('llm_areas', ['key' => 'facturas', 'primary_model' => 'modelo-facturas']);
});

test('crear un área con una clave que no es slug responde 422', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson('/admin/llm/areas', [
        'key' => 'Con Espacios',
        'name' => 'Mal',
        'primary_provider' => 'openai',
    ])->assertUnprocessable();

    $this->assertDatabaseMissing('llm_areas', ['key' => 'Con Espacios']);
});

test('crear un área con una clave repetida responde 422', function () {
    llmArea(['key' => 'soporte']);

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson('/admin/llm/areas', [
        'key' => 'soporte',
        'name' => 'Otra',
        'primary_provider' => 'openai',
    ])->assertUnprocessable();
});

test('crear un área con un proveedor no soportado responde 422', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson('/admin/llm/areas', [
        'key' => 'nueva',
        'name' => 'Nueva',
        'primary_provider' => 'proveedor-inventado',
    ])->assertUnprocessable();
});

test('crear un área sin llm.manage responde 403 y no la guarda', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->postJson('/admin/llm/areas', [
        'key' => 'nueva',
        'name' => 'Nueva',
        'primary_provider' => 'openai',
    ])->assertForbidden();

    $this->assertDatabaseMissing('llm_areas', ['key' => 'nueva']);
});

// ── áreas: edición y baja ───────────────────────────────────────────────────

test('quien gestiona la IA cambia el modelo de un área sin desplegar', function () {
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->putJson("/admin/llm/areas/{$area->id}", [
        'primary_model' => 'modelo-nuevo',
        'is_active' => false,
    ])->assertOk();

    $this->assertDatabaseHas('llm_areas', ['id' => $area->id, 'primary_model' => 'modelo-nuevo', 'is_active' => false]);
});

test('editar un área con un proveedor no soportado responde 422', function () {
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->putJson("/admin/llm/areas/{$area->id}", [
        'primary_provider' => 'inventado',
    ])->assertUnprocessable();

    $this->assertDatabaseHas('llm_areas', ['id' => $area->id, 'primary_provider' => 'openai']);
});

test('editar un área inexistente responde 404', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->putJson('/admin/llm/areas/999999', [
        'name' => 'X',
    ])->assertNotFound();
});

test('quien gestiona la IA elimina un área', function () {
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->deleteJson("/admin/llm/areas/{$area->id}")
        ->assertOk();

    expect(LlmArea::find($area->id))->toBeNull();
});

test('eliminar un área sin llm.manage responde 403 y la conserva', function () {
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->deleteJson("/admin/llm/areas/{$area->id}")
        ->assertForbidden();

    expect(LlmArea::find($area->id))->not->toBeNull();
});

// ── áreas: prueba de conectividad ───────────────────────────────────────────

test('probar un área devuelve la respuesta del modelo y deja rastro en la auditoría', function () {
    Http::fake(['*' => Http::response(llmRespuestaFalsa('ok'), 200)]);
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson("/admin/llm/areas/{$area->id}/test")
        ->assertOk()
        ->assertJsonPath('data.content', 'ok')
        ->assertJsonPath('data.total_tokens', 10);

    expect(LlmAuditLog::where('use_case', 'soporte.test')->where('status', LlmAuditLog::STATUS_SUCCESS)->count())
        ->toBe(1);
});

test('probar un área cuyo proveedor falla responde 422 con el motivo', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson("/admin/llm/areas/{$area->id}/test")
        ->assertUnprocessable();

    expect(LlmAuditLog::where('use_case', 'soporte.test')->where('status', LlmAuditLog::STATUS_ERROR)->exists())
        ->toBeTrue();
});

test('probar un área sin credenciales no llama al proveedor', function () {
    config()->set('llm.api_key', '');
    Http::fake();
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->postJson("/admin/llm/areas/{$area->id}/test")
        ->assertUnprocessable();

    Http::assertNothingSent();
});

test('probar un área sin permiso llm.manage responde 403 y no llama al proveedor', function () {
    Http::fake();
    $area = llmArea();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->postJson("/admin/llm/areas/{$area->id}/test")
        ->assertForbidden();

    Http::assertNothingSent();
});

// ── auditoría ───────────────────────────────────────────────────────────────

test('quien tiene llm.logs lista la auditoría de IA', function () {
    llmRegistro();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/logs')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

test('la auditoría no se ve sin llm.logs aunque se tenga llm.manage', function () {
    llmRegistro();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->getJson('/admin/llm/logs')
        ->assertForbidden();
});

test('la auditoría sin token responde 401', function () {
    $this->getJson('/admin/llm/logs')->assertUnauthorized();
});

test('la auditoría filtra por caso de uso', function () {
    $ask = llmRegistro(['use_case' => 'docs.ask']);
    llmRegistro(['use_case' => 'soporte.clasificar']);

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/logs?use_case=docs.ask')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $ask->id);
});

test('la auditoría filtra por estado', function () {
    llmRegistro(['status' => LlmAuditLog::STATUS_SUCCESS]);
    $fallo = llmRegistro(['status' => LlmAuditLog::STATUS_ERROR, 'error' => 'timeout']);

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/logs?status=error')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $fallo->id);
});

test('un registro de auditoría se consulta por id', function () {
    $registro = llmRegistro();

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson("/admin/llm/logs/{$registro->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $registro->id);
});

test('pedir un registro de auditoría inexistente responde 404', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/logs/999999')
        ->assertNotFound();
});

test('las estadísticas suman las llamadas y los tokens del periodo', function () {
    llmRegistro(['total_tokens' => 10]);
    llmRegistro(['total_tokens' => 15, 'status' => LlmAuditLog::STATUS_ERROR]);

    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.logs')))->getJson('/admin/llm/logs/stats')
        ->assertOk()
        ->assertJsonPath('data.calls', 2)
        ->assertJsonPath('data.total_tokens', 25)
        ->assertJsonPath('data.by_status.error', 1);
});

test('las estadísticas sin llm.logs responden 403', function () {
    $this->withHeaders(llmCabeceras(llmUsuarioCon('llm.manage')))->getJson('/admin/llm/logs/stats')
        ->assertForbidden();
});
