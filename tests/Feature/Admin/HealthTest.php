<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Health checks. Públicos: /health, /health/live, /health/ready.
 * Con auth:api: /health/detailed. El detalle no exige permiso, solo token.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

test('el health check general es público y responde el estado de sus servicios', function () {
    $this->getJson('/health')
        ->assertJsonStructure(['status', 'timestamp', 'services' => ['database', 'redis']])
        ->assertJsonPath('services.database.status', 'ok');
});

test('la sonda de vida responde alive sin token', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertJsonPath('status', 'alive')
        ->assertJsonStructure(['status', 'timestamp']);
});

test('la sonda de disponibilidad responde ready sin token cuando la base y Redis responden', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('services.database.status', 'ok')
        ->assertJsonPath('services.redis.status', 'ok');
});

test('la sonda de disponibilidad responde 503 sin revelar el error si la base no responde', function () {
    DB::partialMock()
        ->shouldReceive('connection')
        ->andThrow(new RuntimeException('SQLSTATE[08006] host=10.0.0.5 password=supersecreto'));

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('services.database.status', 'error')
        ->assertJsonPath('services.database.message', 'Connection failed')
        ->assertDontSee('supersecreto')
        ->assertDontSee('10.0.0.5');
});

test('el estado detallado exige token', function () {
    $this->getJson('/health/detailed')->assertUnauthorized();
});

test('quien tiene token ve el estado detallado de la base y Redis', function () {
    $usuario = User::factory()->create();
    $usuario->roles()->attach(Role::where('name', 'user')->value('id'));

    $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)])
        ->getJson('/health/detailed')
        ->assertOk()
        ->assertJsonStructure(['status', 'version', 'timestamp', 'environment', 'services' => ['database', 'redis']])
        // 'degraded' es válido: Horizon/Meilisearch pueden no estar configurados en test.
        ->assertJsonPath('status', fn ($estado) => in_array($estado, ['healthy', 'degraded']))
        ->assertJsonPath('services.database.status', 'ok')
        ->assertJsonPath('services.redis.status', 'ok')
        ->assertJsonPath('services.redis.read_write', true);
});

test('el estado detallado no expone la contraseña de la base de datos', function () {
    $usuario = User::factory()->admin()->create();
    $password = (string) config('database.connections.'.config('database.default').'.password');

    // Sin contraseña configurada la comprobación no significaría nada.
    expect($password)->not->toBe('');

    $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)])
        ->getJson('/health/detailed')
        ->assertOk()
        ->assertDontSee($password);
});
