<?php

use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /admin/logs. Lectura: system.logs. Limpieza: system.logs-delete
 * (permiso aparte). Las pruebas de 403 cruzadas entre ambos ya viven en
 * tests/Feature/Authorization/LogsAccessTest.php; aquí se cubren el 401, el
 * contenido, los filtros, la limpieza por antigüedad y que no se filtran secretos.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function logsCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function logsUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::whereIn('name', $permisos)->pluck('id'));

    return $user;
}

/** Fecha de creación retrocedida: created_at no se rellena solo al crear. */
function logsEnvejecer(string $tabla, int $id, int $dias): void
{
    DB::table($tabla)->where('id', $id)->update(['created_at' => now()->subDays($dias)]);
}

// ── Listados: lectura ───────────────────────────────────────────────────────

test('quien tiene system.logs lista los activity logs paginados', function () {
    // Otras acciones (crear usuarios, asignar permisos) generan más logs: se comprueba por id.
    $creados = ActivityLog::factory()->count(3)->create();

    $respuesta = $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/activity')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data', 'meta' => ['total', 'per_page', 'current_page']]);

    expect(collect($respuesta->json('data'))->pluck('id')->all())->toContain(...$creados->pluck('id')->all());
});

test('el listado de activity logs filtra por acción', function () {
    $creado = ActivityLog::factory()->create(['action' => ActivityLog::ACTION_CREATED]);
    $borrado = ActivityLog::factory()->create(['action' => ActivityLog::ACTION_DELETED]);

    // La BD puede traer logs de otros tests: se comprueba por id y que cada fila cumple el filtro.
    $respuesta = $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))
        ->getJson('/admin/logs/activity?action='.ActivityLog::ACTION_DELETED)
        ->assertOk();

    $filas = collect($respuesta->json('data'));
    expect($filas->pluck('id')->all())->toContain($borrado->id)->not->toContain($creado->id);
    expect($filas->every(fn ($fila) => $fila['action'] === ActivityLog::ACTION_DELETED))->toBeTrue();
});

test('el listado de api logs filtra por usuario', function () {
    $admin = logsUsuarioCon('system.logs');
    ApiLog::factory()->create(['user_id' => $admin->id, 'request_path' => 'propio']);
    ApiLog::factory()->create(['request_path' => 'ajeno']);

    $this->withHeaders(logsCabeceras($admin))->getJson("/admin/logs/api?user_id={$admin->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.request_path', 'propio');
});

test('el listado de api logs filtra por método', function () {
    $post = ApiLog::factory()->create(['request_method' => 'POST']);
    $get = ApiLog::factory()->create(['request_method' => 'GET']);

    $ids = collect($this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/api?method=post&per_page=100')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($post->id)->not->toContain($get->id);
});

test('el listado de security logs filtra por tipo de evento', function () {
    $fallo = SecurityLog::factory()->create(['event_type' => SecurityLog::EVENT_LOGIN_FAILURE]);
    $exito = SecurityLog::factory()->create(['event_type' => SecurityLog::EVENT_LOGIN_SUCCESS]);

    $respuesta = $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))
        ->getJson('/admin/logs/security?event_type='.SecurityLog::EVENT_LOGIN_FAILURE)
        ->assertOk();

    $filas = collect($respuesta->json('data'));
    expect($filas->pluck('id')->all())->toContain($fallo->id)->not->toContain($exito->id);
    expect($filas->every(fn ($fila) => $fila['event_type'] === SecurityLog::EVENT_LOGIN_FAILURE))->toBeTrue();
});

test('el listado de logs respeta el rango de fechas from y to', function () {
    $reciente = ActivityLog::factory()->create();
    $antiguo = ActivityLog::factory()->create();
    logsEnvejecer('logs_activity', $antiguo->id, 30);

    $desde = now()->subDays(7)->toDateString();

    $respuesta = $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))
        ->getJson("/admin/logs/activity?from={$desde}")
        ->assertOk();

    $ids = collect($respuesta->json('data'))->pluck('id')->all();
    expect($ids)->toContain($reciente->id)->not->toContain($antiguo->id);
});

test('leer los logs sin token responde 401', function (string $tipo) {
    $this->getJson("/admin/logs/{$tipo}")->assertUnauthorized();
})->with(['activity', 'api', 'security']);

test('borrar los logs sin token responde 401 y no borra nada', function (string $tipo, string $tabla) {
    $log = match ($tipo) {
        'activity' => ActivityLog::factory()->create(),
        'api' => ApiLog::factory()->create(),
        'security' => SecurityLog::factory()->create(),
    };
    logsEnvejecer($tabla, $log->id, 400);

    $this->deleteJson("/admin/logs/{$tipo}")->assertUnauthorized();

    $this->assertDatabaseHas($tabla, ['id' => $log->id]);
})->with([
    ['activity', 'logs_activity'],
    ['api', 'logs_api'],
    ['security', 'logs_security'],
]);

// ── Limpieza: borrado por antigüedad ────────────────────────────────────────

test('la limpieza borra solo los logs anteriores al número de días indicado', function () {
    $admin = logsUsuarioCon('system.logs-delete');

    $viejo = ApiLog::factory()->create();
    $reciente = ApiLog::factory()->create();
    logsEnvejecer('logs_api', $viejo->id, 10);
    logsEnvejecer('logs_api', $reciente->id, 1);

    $this->withHeaders(logsCabeceras($admin))->deleteJson('/admin/logs/api', ['days' => 5])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', fn ($mensaje) => str_ends_with($mensaje, ' api logs eliminados (anteriores a 5 días)'));

    $this->assertDatabaseMissing('logs_api', ['id' => $viejo->id]);
    $this->assertDatabaseHas('logs_api', ['id' => $reciente->id]);
});

test('la limpieza usa la retención por defecto de cada tipo de log', function (string $tipo, string $tabla, int $dias) {
    $admin = logsUsuarioCon('system.logs-delete');

    $log = match ($tipo) {
        'activity' => ActivityLog::factory()->create(),
        'api' => ApiLog::factory()->create(),
        'security' => SecurityLog::factory()->create(),
    };
    $dentro = match ($tipo) {
        'activity' => ActivityLog::factory()->create(),
        'api' => ApiLog::factory()->create(),
        'security' => SecurityLog::factory()->create(),
    };

    logsEnvejecer($tabla, $log->id, $dias + 10);
    logsEnvejecer($tabla, $dentro->id, $dias - 10);

    $this->withHeaders(logsCabeceras($admin))->deleteJson("/admin/logs/{$tipo}")->assertOk();

    $this->assertDatabaseMissing($tabla, ['id' => $log->id]);
    $this->assertDatabaseHas($tabla, ['id' => $dentro->id]);
})->with([
    ['activity', 'logs_activity', 90],
    ['api', 'logs_api', 30],
    ['security', 'logs_security', 180],
]);

test('quien solo tiene system.logs no puede limpiar los logs', function () {
    $log = ApiLog::factory()->create();
    logsEnvejecer('logs_api', $log->id, 400);

    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->deleteJson('/admin/logs/api')
        ->assertForbidden();

    $this->assertDatabaseHas('logs_api', ['id' => $log->id]);
});

// ── Secretos: la respuesta no debe contener credenciales ────────────────────

test('el log de una petición no guarda ni devuelve la contraseña ni el token enviados', function () {
    $admin = User::factory()->admin()->create();
    $jwt = JWTAuth::fromUser($admin);
    $cabeceras = ['Authorization' => 'Bearer '.$jwt];

    // Petición real que atraviesa el middleware de logging; el cuerpo lleva secretos.
    $this->withHeaders($cabeceras)->postJson('/settings', [
        'key' => 'app.secretos',
        'value' => 'valor',
        'type' => 'string',
        'password' => 'ContrasenaSecreta-987',
        'access_token' => 'TokenSecreto-654',
    ])->assertCreated();

    $this->assertDatabaseHas('logs_api', ['request_path' => 'settings']);

    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/api')
        ->assertOk()
        ->assertSee('[REDACTED]')
        ->assertDontSee('ContrasenaSecreta-987')
        ->assertDontSee('TokenSecreto-654')
        ->assertDontSee($jwt);
});

test('el registro de actividad no expone el hash de la contraseña de un usuario', function () {
    $usuario = User::factory()->create();
    // Lectura cruda: el atributo casteado 'hashed' no es el valor que guarda la BD.
    $hash = DB::table('users')->where('id', $usuario->id)->value('password');

    ActivityLogger::log($usuario, 'updated', ['name' => 'anterior']);

    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/activity')
        ->assertOk()
        ->assertDontSee($hash);
});

// ── Filtros del listado de api logs y validación de parámetros ──────────────

test('filtrar api logs por código de estado devuelve solo los que coinciden', function () {
    $error = ApiLog::factory()->create(['response_status' => 503]);
    $ok = ApiLog::factory()->create(['response_status' => 200]);

    $ids = collect($this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/api?status_code=503&per_page=100')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($error->id)->not->toContain($ok->id);
});

test('filtrar api logs por un código de estado no numérico responde 422', function () {
    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/api?status_code=abc')
        ->assertUnprocessable();
});

test('filtrar api logs por endpoint devuelve solo los que coinciden', function () {
    $settings = ApiLog::factory()->create(['request_path' => 'zz-settings-filtro/1']);
    $menu = ApiLog::factory()->create(['request_path' => 'menu']);

    $ids = collect($this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/api?endpoint=zz-settings-filtro&per_page=100')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($settings->id)->not->toContain($menu->id);
});

test('una fecha no válida en from responde 422 y no 500', function () {
    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/activity?from=no-es-una-fecha')
        ->assertUnprocessable();
});

test('un rango con to anterior a from responde 422', function () {
    $this->withHeaders(logsCabeceras(logsUsuarioCon('system.logs')))->getJson('/admin/logs/security?from=2026-02-01&to=2026-01-01')
        ->assertUnprocessable();
});
