<?php

use App\Contracts\MaintenanceServiceInterface;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;

uses(RefreshDatabase::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
    $this->usuario = User::factory()->create();
    $this->usuario->assignRole(Role::where('name', 'user')->first());
});

function mantToken(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

test('sin mantenimiento la API responde normal', function () {
    $this->getJson('/health')->assertOk();
    $this->asHeaders(mantToken($this->usuario))->getJson('/auth/me')->assertOk();
});

test('un admin activa el mantenimiento con mensaje y fin y el resto recibe 503 con Retry-After', function () {
    $until = now()->addHour()->toIso8601String();
    $headers = mantToken($this->admin);
    $this->asHeaders($headers)->postJson('/admin/maintenance', ['message' => 'Migrando', 'until' => $until])->assertOk();

    $usuario = mantToken($this->usuario);
    $res = $this->asHeaders($usuario)->getJson('/user/profile')->assertStatus(503);

    expect($res->json('code'))->toBe('maintenance')
        ->and($res->json('detail'))->toBe('Migrando')
        ->and((int) $res->headers->get('Retry-After'))->toBeBetween(3000, 3700)
        ->and($res->json('status'))->toBe(503)
        ->and($res->json('request_id'))->not->toBeNull();
});

test('la salud y los webhooks entrantes pasan en mantenimiento', function () {
    app(MaintenanceServiceInterface::class)->enable(null, null);

    $this->getJson('/health')->assertOk();
    $this->getJson('/health/live')->assertOk();
    expect($this->postJson('/webhooks/inbound/inexistente')->status())->not->toBe(503);
    expect($this->postJson('/webhooks/mail')->status())->not->toBe(503);
});

test('un usuario normal no puede activar ni desactivar el mantenimiento', function () {
    $this->asHeaders(mantToken($this->usuario))->postJson('/admin/maintenance')->assertForbidden();
    app(MaintenanceServiceInterface::class)->enable(null, null);
    $this->asHeaders(mantToken($this->usuario))->deleteJson('/admin/maintenance')->assertStatus(503);

    expect(app(MaintenanceServiceInterface::class)->state())->not->toBeNull();
});

test('un admin con token puede seguir usando la API en mantenimiento y desactivarlo', function () {
    app(MaintenanceServiceInterface::class)->enable('x', null);

    $this->asHeaders(mantToken($this->admin))->getJson('/admin/maintenance')->assertOk()->assertJsonPath('data.enabled', true);
    $this->asHeaders(mantToken($this->admin))->deleteJson('/admin/maintenance')->assertOk()->assertJsonPath('data.enabled', false);

    $this->asHeaders(mantToken($this->usuario))->getJson('/auth/me')->assertOk();
});

test('la lista blanca de IPs pasa en mantenimiento', function () {
    config(['maintenance.allow_ips' => ['203.0.113.0/24']]);
    app(MaintenanceServiceInterface::class)->enable(null, null);

    $this->asHeaders(mantToken($this->usuario))->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/auth/me')->assertOk();
    $this->asHeaders(mantToken($this->usuario))->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson('/auth/me')->assertStatus(503);
});

test('activar y desactivar por comando comparte el estado y queda auditado', function () {
    Artisan::call('api:down', ['--message' => 'Corte', '--until' => now()->addMinutes(10)->toIso8601String()]);

    $state = app(MaintenanceServiceInterface::class)->state();
    expect($state['message'])->toBe('Corte')->and($state['source'])->toBe('cache');
    $this->asHeaders(mantToken($this->usuario))->getJson('/auth/me')->assertStatus(503);

    Artisan::call('api:up');

    expect(app(MaintenanceServiceInterface::class)->state())->toBeNull();
    $this->asHeaders(mantToken($this->usuario))->getJson('/auth/me')->assertOk();
    expect(AuditLog::query()->whereIn('event', ['maintenance.enable', 'maintenance.disable'])->count())->toBe(2);
});

test('el modo forzado por entorno no se apaga desde la API', function () {
    config(['maintenance.enabled' => true, 'maintenance.message' => 'Despliegue']);

    $this->asHeaders(mantToken($this->admin))->deleteJson('/admin/maintenance')->assertOk()->assertJsonPath('data.enabled', true);
    $this->asHeaders(mantToken($this->usuario))->getJson('/auth/me')->assertStatus(503);
});

test('un fin estimado en el pasado en la petición se rechaza', function () {
    $this->asHeaders(mantToken($this->admin))->postJson('/admin/maintenance', ['until' => now()->subHour()->toIso8601String()])->assertStatus(422);
});
