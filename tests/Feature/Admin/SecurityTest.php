<?php

use App\Jobs\RunSecurityScanJob;
use App\Models\Blacklist;
use App\Models\Permission;
use App\Models\SecurityScan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /admin/blacklist (security.blacklist) y /admin/security/scans
 * (security.audit). Ningún escaneo corre de verdad: el job se intercepta con
 * Bus::fake(), así que no hay llamadas al LLM ni a la cola.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function seguridadCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function seguridadUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();
    $user->permissions()->attach(Permission::whereIn('name', $permisos)->pluck('id'));

    return $user;
}

function seguridadBloqueo(array $attributes = []): Blacklist
{
    $admin = User::factory()->admin()->create();

    return Blacklist::create(array_merge([
        'email' => 'estafador@example.com',
        'reason' => 'Contracargos repetidos',
        'added_by' => $admin->id,
    ], $attributes));
}

function seguridadEscaneo(string $status, ?int $creadoPor = null): SecurityScan
{
    return SecurityScan::create([
        'created_by' => $creadoPor ?? User::factory()->admin()->create()->id,
        'status' => $status,
        'salt' => SecurityScan::generateSalt(),
    ]);
}

// ── Lista negra: listado ────────────────────────────────────────────────────

test('quien tiene security.blacklist lista los bloqueos paginados', function () {
    seguridadBloqueo(['email' => 'uno@example.com']);
    seguridadBloqueo(['email' => 'dos@example.com']);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->getJson('/admin/blacklist')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data', 'meta' => ['total', 'per_page']])
        ->assertJsonPath('meta.total', 2);
});

test('el listado de bloqueos busca por correo, sin distinguir mayúsculas', function () {
    seguridadBloqueo(['email' => 'ana@example.com']);
    seguridadBloqueo(['email' => 'otro@example.com']);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/blacklist?search=ANA@')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'ana@example.com');
});

test('listar bloqueos sin token responde 401', function () {
    $this->getJson('/admin/blacklist')->assertUnauthorized();
});

test('listar bloqueos sin security.blacklist responde 403', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))->getJson('/admin/blacklist')
        ->assertForbidden()
        ->assertJsonPath('status', 403);
});

// ── Lista negra: alta ───────────────────────────────────────────────────────

test('quien tiene security.blacklist bloquea un correo y queda quién lo bloqueó', function () {
    $gestor = seguridadUsuarioCon('security.blacklist');

    $this->withHeaders(seguridadCabeceras($gestor))->postJson('/admin/blacklist', [
        'email' => 'nuevo@example.com',
        'reason' => 'Fraude en pagos',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'nuevo@example.com');

    $this->assertDatabaseHas('blacklists', [
        'email' => 'nuevo@example.com',
        'reason' => 'Fraude en pagos',
        'added_by' => $gestor->id,
    ]);
});

test('bloquear un usuario existente por su id', function () {
    $usuario = User::factory()->create();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/blacklist', [
        'user_id' => $usuario->id,
        'reason' => 'Cuenta comprometida',
    ])->assertCreated();

    $this->assertDatabaseHas('blacklists', ['user_id' => $usuario->id]);
});

test('bloquear sin ninguna identidad responde 422 y no guarda nada', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/blacklist', [
        'reason' => 'Sin a quién',
    ])->assertUnprocessable();

    expect(Blacklist::count())->toBe(0);
});

test('bloquear sin motivo responde 422', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/blacklist', [
        'email' => 'nuevo@example.com',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect(Blacklist::count())->toBe(0);
});

test('bloquear con un correo mal formado responde 422', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/blacklist', [
        'email' => 'no-es-correo',
        'reason' => 'Motivo',
    ])->assertUnprocessable();
});

test('bloquear un usuario que no existe responde 422', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/blacklist', [
        'user_id' => 999999,
        'reason' => 'Motivo',
    ])->assertUnprocessable();
});

test('bloquear sin token responde 401', function () {
    $this->postJson('/admin/blacklist', ['email' => 'nuevo@example.com', 'reason' => 'Motivo'])
        ->assertUnauthorized();

    expect(Blacklist::count())->toBe(0);
});

test('bloquear sin security.blacklist responde 403 y no guarda nada', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))->postJson('/admin/blacklist', [
        'email' => 'nuevo@example.com',
        'reason' => 'Motivo',
    ])->assertForbidden();

    expect(Blacklist::count())->toBe(0);
});

// ── Lista negra: comprobación ───────────────────────────────────────────────

test('comprobar un correo bloqueado devuelve blocked true con el motivo', function () {
    seguridadBloqueo(['email' => 'estafador@example.com', 'reason' => 'Contracargos']);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/blacklist/check?email=ESTAFADOR@example.com')
        ->assertOk()
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.reason', 'Contracargos')
        ->assertJsonStructure(['data' => ['blocked', 'reason', 'blocked_at']]);
});

test('comprobar un documento bloqueado devuelve blocked true', function () {
    seguridadBloqueo(['email' => null, 'identity_document' => 'X1234567']);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/blacklist/check?identity_document=X1234567')
        ->assertOk()
        ->assertJsonPath('data.blocked', true);
});

test('comprobar una identidad que no está bloqueada devuelve blocked false', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/blacklist/check?email=limpio@example.com')
        ->assertOk()
        ->assertJsonPath('data.blocked', false)
        ->assertJsonPath('data.reason', null);
});

test('comprobar sin ningún dato devuelve blocked false', function () {
    seguridadBloqueo();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/blacklist/check')
        ->assertOk()
        ->assertJsonPath('data.blocked', false);
});

test('comprobar sin token responde 401', function () {
    $this->getJson('/admin/blacklist/check?email=estafador@example.com')->assertUnauthorized();
});

test('comprobar sin security.blacklist responde 403', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->getJson('/admin/blacklist/check?email=estafador@example.com')
        ->assertForbidden();
});

// ── Lista negra: baja ───────────────────────────────────────────────────────

test('quien tiene security.blacklist quita un bloqueo (borrado lógico)', function () {
    $bloqueo = seguridadBloqueo();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->deleteJson("/admin/blacklist/{$bloqueo->id}", ['reason' => 'prueba'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('blacklists', ['id' => $bloqueo->id]);
    expect(Blacklist::isBlocked('estafador@example.com'))->toBeFalse();
});

test('quitar un bloqueo inexistente responde 404', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->deleteJson('/admin/blacklist/999999')
        ->assertNotFound();
});

test('quitar un bloqueo sin security.blacklist responde 403 y lo mantiene', function () {
    $bloqueo = seguridadBloqueo();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->deleteJson("/admin/blacklist/{$bloqueo->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('blacklists', ['id' => $bloqueo->id, 'deleted_at' => null]);
});

test('quitar un bloqueo sin token responde 401', function () {
    $bloqueo = seguridadBloqueo();

    $this->deleteJson("/admin/blacklist/{$bloqueo->id}")->assertUnauthorized();
});

// ── Escaneos: lanzar ────────────────────────────────────────────────────────

test('quien tiene security.audit lanza un escaneo que queda pendiente y encolado', function () {
    Bus::fake();
    $auditor = seguridadUsuarioCon('security.audit');

    $this->withHeaders(seguridadCabeceras($auditor))->postJson('/admin/security/scans')
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', SecurityScan::STATUS_PENDING);

    $this->assertDatabaseHas('security_scans', [
        'created_by' => $auditor->id,
        'status' => SecurityScan::STATUS_PENDING,
    ]);

    Bus::assertDispatched(RunSecurityScanJob::class);
});

test('no se lanza un escaneo mientras hay otro en curso y responde 409', function () {
    Bus::fake();
    $activo = seguridadEscaneo(SecurityScan::STATUS_RUNNING);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))->postJson('/admin/security/scans')
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.scan_id', $activo->id)
        ->assertJsonPath('errors.status', SecurityScan::STATUS_RUNNING);

    expect(SecurityScan::count())->toBe(1);
    Bus::assertNotDispatched(RunSecurityScanJob::class);
});

test('un escaneo ya terminado no impide lanzar otro', function () {
    Bus::fake();
    seguridadEscaneo(SecurityScan::STATUS_COMPLETED);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))->postJson('/admin/security/scans')
        ->assertCreated();

    expect(SecurityScan::count())->toBe(2);
    Bus::assertDispatched(RunSecurityScanJob::class);
});

test('lanzar un escaneo sin token responde 401', function () {
    Bus::fake();

    $this->postJson('/admin/security/scans')->assertUnauthorized();

    expect(SecurityScan::count())->toBe(0);
    Bus::assertNotDispatched(RunSecurityScanJob::class);
});

test('lanzar un escaneo sin security.audit responde 403 y no crea nada', function () {
    Bus::fake();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))->postJson('/admin/security/scans')
        ->assertForbidden();

    expect(SecurityScan::count())->toBe(0);
    Bus::assertNotDispatched(RunSecurityScanJob::class);
});

// ── Escaneos: consulta ──────────────────────────────────────────────────────

test('quien tiene security.audit lista los escaneos', function () {
    seguridadEscaneo(SecurityScan::STATUS_COMPLETED);
    seguridadEscaneo(SecurityScan::STATUS_FAILED);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))->getJson('/admin/security/scans')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 2);
});

test('quien tiene security.audit ve un escaneo sin exponer su sal', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_COMPLETED);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->getJson("/admin/security/scans/{$escaneo->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $escaneo->id)
        ->assertJsonPath('data.status', SecurityScan::STATUS_COMPLETED)
        ->assertJsonMissingPath('data.salt')
        ->assertDontSee($escaneo->salt);
});

test('pedir un escaneo inexistente responde 404', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->getJson('/admin/security/scans/999999')
        ->assertNotFound();
});

test('consultar escaneos sin security.audit responde 403', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_COMPLETED);
    $sinPermiso = seguridadCabeceras(seguridadUsuarioCon('security.blacklist'));

    $this->withHeaders($sinPermiso)->getJson('/admin/security/scans')->assertForbidden();
    $this->withHeaders($sinPermiso)->getJson("/admin/security/scans/{$escaneo->id}")->assertForbidden();
});

test('consultar escaneos sin token responde 401', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_COMPLETED);

    $this->getJson('/admin/security/scans')->assertUnauthorized();
    $this->getJson("/admin/security/scans/{$escaneo->id}")->assertUnauthorized();
});

// ── Escaneos: vista previa (sin escaneo ni LLM) ─────────────────────────────

test('la vista previa devuelve la severidad y los hallazgos locales sin lanzar un escaneo', function () {
    Bus::fake();

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->getJson('/admin/security/scans/preview')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['severity', 'findings']]);

    expect(SecurityScan::count())->toBe(0);
    Bus::assertNotDispatched(RunSecurityScanJob::class);
});

test('la vista previa sin token responde 401', function () {
    $this->getJson('/admin/security/scans/preview')->assertUnauthorized();
});

test('la vista previa sin security.audit responde 403', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->getJson('/admin/security/scans/preview')
        ->assertForbidden();
});

// ── Escaneos: detener ───────────────────────────────────────────────────────

test('detener un escaneo pendiente lo marca como detenido y registra quién lo paró', function () {
    $auditor = seguridadUsuarioCon('security.audit');
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_PENDING);

    $this->withHeaders(seguridadCabeceras($auditor))->postJson("/admin/security/scans/{$escaneo->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', SecurityScan::STATUS_STOPPED);

    $this->assertDatabaseHas('security_scans', [
        'id' => $escaneo->id,
        'status' => SecurityScan::STATUS_STOPPED,
        'stopped_by' => $auditor->id,
    ]);
});

test('detener un escaneo ya terminado no cambia su estado', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_COMPLETED);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->postJson("/admin/security/scans/{$escaneo->id}/stop")
        ->assertOk();

    $this->assertDatabaseHas('security_scans', [
        'id' => $escaneo->id,
        'status' => SecurityScan::STATUS_COMPLETED,
        'stopped_by' => null,
    ]);
});

test('detener un escaneo inexistente responde 404', function () {
    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.audit')))
        ->postJson('/admin/security/scans/999999/stop')
        ->assertNotFound();
});

test('detener un escaneo sin security.audit responde 403 y no lo cambia', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_PENDING);

    $this->withHeaders(seguridadCabeceras(seguridadUsuarioCon('security.blacklist')))
        ->postJson("/admin/security/scans/{$escaneo->id}/stop")
        ->assertForbidden();

    $this->assertDatabaseHas('security_scans', ['id' => $escaneo->id, 'status' => SecurityScan::STATUS_PENDING]);
});

test('detener un escaneo sin token responde 401', function () {
    $escaneo = seguridadEscaneo(SecurityScan::STATUS_PENDING);

    $this->postJson("/admin/security/scans/{$escaneo->id}/stop")->assertUnauthorized();
});
