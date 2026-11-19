<?php

use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailProvider;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoints de /admin/mail. Administración (proveedores, cuentas, plantillas y
 * ajustes): permiso mail.manage. Historial de envíos: permiso mail.logs.
 * El envío de prueba usa el driver `array` (nada sale de la máquina): los
 * mensajes se leen del transporte en memoria.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.fallback' => 'array', 'queue.default' => 'sync']);
});

function mailCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function mailUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();

    foreach ($permisos as $permiso) {
        $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));
    }

    return $user;
}

function mailProveedorArray(): MailProvider
{
    return MailProvider::firstOrCreate(
        ['driver' => 'array'],
        ['name' => 'Array', 'is_active' => true, 'sort_order' => 0],
    );
}

function mailCuenta(array $extra = []): MailAccount
{
    return MailAccount::create(array_merge([
        'name' => 'Cuenta de pruebas',
        'mail_provider_id' => mailProveedorArray()->id,
        'from_email' => 'hola@ejemplo.com',
        'from_name' => 'Equipo',
        'is_active' => true,
        'is_default' => false,
        'priority' => 0,
    ], $extra));
}

function mailRegistro(array $extra = []): MailLog
{
    return MailLog::create(array_merge([
        'template_key' => 'welcome',
        'to_email' => 'ana@ejemplo.com',
        'subject' => 'Bienvenida',
        'status' => MailLog::STATUS_SENT,
    ], $extra));
}

function mailEntregados(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
}

// ── proveedores ─────────────────────────────────────────────────────────────

test('quien gestiona el correo lista los proveedores', function () {
    mailProveedorArray();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/providers')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta']);
});

test('los proveedores de correo no se ven sin mail.manage', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/providers')
        ->assertForbidden();
});

test('los proveedores de correo sin token responden 401', function () {
    $this->getJson('/admin/mail/providers')->assertUnauthorized();
});

test('quien gestiona el correo crea un proveedor', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/providers', [
        'name' => 'Pruebas',
        'driver' => 'array',
        'config' => [],
        'is_active' => true,
    ])->assertStatus(201);

    $this->assertDatabaseHas('mail_providers', ['name' => 'Pruebas', 'driver' => 'array']);
});

test('crear un proveedor con un driver desconocido responde 422', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/providers', [
        'name' => 'Raro',
        'driver' => 'carrier-pigeon',
    ])->assertUnprocessable();
});

test('crear un proveedor con un puerto fuera de rango responde 422', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/providers', [
        'name' => 'Smtp',
        'driver' => 'smtp',
        'config' => ['host' => 'smtp.ejemplo.com', 'port' => 70000],
    ])->assertUnprocessable();
});

test('un proveedor se consulta por id y un id inexistente responde 404', function () {
    $proveedor = mailProveedorArray();
    $admin = mailUsuarioCon('mail.manage');

    $this->withHeaders(mailCabeceras($admin))->getJson("/admin/mail/providers/{$proveedor->id}")
        ->assertOk()
        ->assertJsonPath('data.driver', 'array');

    $this->withHeaders(mailCabeceras($admin))->getJson('/admin/mail/providers/999999')
        ->assertNotFound();
});

test('quien gestiona el correo actualiza el nombre de un proveedor', function () {
    $proveedor = mailProveedorArray();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson("/admin/mail/providers/{$proveedor->id}", [
        'name' => 'Proveedor renombrado',
    ])->assertOk();

    $this->assertDatabaseHas('mail_providers', ['id' => $proveedor->id, 'name' => 'Proveedor renombrado']);
});

test('quien gestiona el correo elimina un proveedor', function () {
    $proveedor = MailProvider::create(['name' => 'Sobrante', 'driver' => 'array', 'config' => [], 'is_active' => true]);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->deleteJson("/admin/mail/providers/{$proveedor->id}")
        ->assertOk();

    expect(MailProvider::find($proveedor->id))->toBeNull();
});

// ── cuentas de envío ────────────────────────────────────────────────────────

test('quien gestiona el correo lista las cuentas de envío', function () {
    mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/accounts')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta']);
});

test('quien gestiona el correo crea una cuenta de envío', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/accounts', [
        'name' => 'Soporte',
        'mail_provider_id' => mailProveedorArray()->id,
        'from_email' => 'soporte@ejemplo.com',
        'from_name' => 'Soporte',
        'is_active' => true,
        'is_default' => false,
        'priority' => 1,
    ])->assertStatus(201);

    $this->assertDatabaseHas('mail_accounts', ['from_email' => 'soporte@ejemplo.com']);
});

test('crear una cuenta con un remitente que no es email responde 422', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/accounts', [
        'name' => 'Mala',
        'from_email' => 'no-es-un-correo',
        'from_name' => 'Mala',
    ])->assertUnprocessable();
});

test('crear una cuenta con un proveedor inexistente responde 422', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->postJson('/admin/mail/accounts', [
        'name' => 'Huérfana',
        'mail_provider_id' => 999999,
        'from_email' => 'huerfana@ejemplo.com',
        'from_name' => 'Huérfana',
    ])->assertUnprocessable();
});

test('una cuenta se consulta por id y un id inexistente responde 404', function () {
    $cuenta = mailCuenta();
    $admin = mailUsuarioCon('mail.manage');

    $this->withHeaders(mailCabeceras($admin))->getJson("/admin/mail/accounts/{$cuenta->id}")
        ->assertOk()
        ->assertJsonPath('data.from_email', 'hola@ejemplo.com');

    $this->withHeaders(mailCabeceras($admin))->getJson('/admin/mail/accounts/999999')
        ->assertNotFound();
});

test('quien gestiona el correo cambia el remitente de una cuenta', function () {
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson("/admin/mail/accounts/{$cuenta->id}", [
        'from_name' => 'Nuevo nombre',
    ])->assertOk();

    $this->assertDatabaseHas('mail_accounts', ['id' => $cuenta->id, 'from_name' => 'Nuevo nombre']);
});

test('no se puede eliminar la cuenta por defecto', function () {
    $cuenta = mailCuenta(['is_default' => true]);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->deleteJson("/admin/mail/accounts/{$cuenta->id}")
        ->assertUnprocessable();

    expect(MailAccount::find($cuenta->id))->not->toBeNull();
});

test('se elimina una cuenta que no es la de por defecto', function () {
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->deleteJson("/admin/mail/accounts/{$cuenta->id}")
        ->assertOk();

    expect(MailAccount::find($cuenta->id))->toBeNull();
});

test('el correo de prueba sale por la cuenta indicada y queda registrado', function () {
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))
        ->postJson("/admin/mail/accounts/{$cuenta->id}/test", ['email' => 'destino@ejemplo.com'])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(mailEntregados())->toHaveCount(1)
        ->and(mailEntregados()[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('destino@ejemplo.com')
        ->and(MailLog::where('to_email', 'destino@ejemplo.com')->value('mail_account_id'))->toBe($cuenta->id);
});

test('el correo de prueba con un email inválido responde 422 y no envía nada', function () {
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))
        ->postJson("/admin/mail/accounts/{$cuenta->id}/test", ['email' => 'no-es-un-correo'])
        ->assertUnprocessable();

    expect(mailEntregados())->toBeEmpty();
});

test('el correo de prueba sin permiso mail.manage responde 403 y no envía nada', function () {
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))
        ->postJson("/admin/mail/accounts/{$cuenta->id}/test", ['email' => 'destino@ejemplo.com'])
        ->assertForbidden();

    expect(mailEntregados())->toBeEmpty();
});

// ── plantillas ──────────────────────────────────────────────────────────────

test('quien gestiona el correo lista las plantillas', function () {
    MailTemplateConfig::create(['template_key' => 'bienvenida-x', 'template_name' => 'Bienvenida X', 'is_active' => true]);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/templates')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta']);
});

test('las plantillas no se ven sin mail.manage', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/templates')
        ->assertForbidden();
});

test('quien gestiona el correo crea las plantillas por defecto sin duplicarlas', function () {
    $admin = mailUsuarioCon('mail.manage');

    $this->withHeaders(mailCabeceras($admin))->postJson('/admin/mail/templates/seed-defaults')->assertOk();
    $this->withHeaders(mailCabeceras($admin))->postJson('/admin/mail/templates/seed-defaults')->assertOk();

    expect(DB::table('mail_template_configs')->where('template_key', 'welcome')->count())->toBe(1)
        ->and(DB::table('mail_template_configs')->where('template_key', 'password_reset')->count())->toBe(1);
});

test('una plantilla se consulta por id y un id inexistente responde 404', function () {
    $plantilla = MailTemplateConfig::create(['template_key' => 'aviso-x', 'template_name' => 'Aviso', 'is_active' => true]);
    $admin = mailUsuarioCon('mail.manage');

    $this->withHeaders(mailCabeceras($admin))->getJson("/admin/mail/templates/{$plantilla->id}")
        ->assertOk()
        ->assertJsonPath('data.template_key', 'aviso-x');

    $this->withHeaders(mailCabeceras($admin))->getJson('/admin/mail/templates/999999')
        ->assertNotFound();
});

test('quien gestiona el correo cambia el asunto y la cuenta de una plantilla', function () {
    $plantilla = MailTemplateConfig::create(['template_key' => 'aviso-y', 'template_name' => 'Aviso', 'is_active' => true]);
    $cuenta = mailCuenta();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson("/admin/mail/templates/{$plantilla->id}", [
        'subject_template' => 'Asunto nuevo',
        'mail_account_id' => $cuenta->id,
    ])->assertOk();

    $this->assertDatabaseHas('mail_template_configs', [
        'id' => $plantilla->id,
        'subject_template' => 'Asunto nuevo',
        'mail_account_id' => $cuenta->id,
    ]);
});

test('actualizar una plantilla con una cuenta inexistente responde 422', function () {
    $plantilla = MailTemplateConfig::create(['template_key' => 'aviso-z', 'template_name' => 'Aviso', 'is_active' => true]);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson("/admin/mail/templates/{$plantilla->id}", [
        'mail_account_id' => 999999,
    ])->assertUnprocessable();
});

test('pausar una plantilla la desactiva y volver a pulsar la activa', function () {
    $plantilla = MailTemplateConfig::create(['template_key' => 'aviso-t', 'template_name' => 'Aviso', 'is_active' => true]);
    $admin = mailUsuarioCon('mail.manage');

    $this->withHeaders(mailCabeceras($admin))->postJson("/admin/mail/templates/{$plantilla->id}/toggle")
        ->assertOk()
        ->assertJsonPath('message', 'Plantilla pausada');
    expect($plantilla->fresh()->is_active)->toBeFalse();

    $this->withHeaders(mailCabeceras($admin))->postJson("/admin/mail/templates/{$plantilla->id}/toggle")
        ->assertOk()
        ->assertJsonPath('message', 'Plantilla activada');
    expect($plantilla->fresh()->is_active)->toBeTrue();
});

// ── ajustes globales ────────────────────────────────────────────────────────

test('quien gestiona el correo consulta los ajustes globales', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/settings')
        ->assertOk()
        ->assertJsonPath('data.'.MailSetting::KEY_GLOBAL_PAUSE, false);
});

test('pausar todo el correo se guarda como ajuste global', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson('/admin/mail/settings', [
        MailSetting::KEY_GLOBAL_PAUSE => true,
        MailSetting::KEY_DAILY_LIMIT => 50,
    ])->assertOk()
        ->assertJsonPath('data.'.MailSetting::KEY_DAILY_LIMIT, 50);

    expect(MailSetting::isGloballyPaused())->toBeTrue()
        ->and((int) MailSetting::getValue(MailSetting::KEY_DAILY_LIMIT))->toBe(50);
});

test('un límite de envíos negativo responde 422 y no cambia el ajuste', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->putJson('/admin/mail/settings', [
        MailSetting::KEY_RATE_LIMIT => -1,
    ])->assertUnprocessable();

    expect((int) MailSetting::getValue(MailSetting::KEY_RATE_LIMIT, 0))->toBe(0);
});

test('los ajustes de correo sin mail.manage responden 403', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->putJson('/admin/mail/settings', [
        MailSetting::KEY_GLOBAL_PAUSE => true,
    ])->assertForbidden();

    expect(MailSetting::isGloballyPaused())->toBeFalse();
});

// ── historial de envíos ─────────────────────────────────────────────────────

test('quien tiene mail.logs lista el historial de envíos', function () {
    mailRegistro();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('el historial no se ve con solo mail.manage', function () {
    mailRegistro();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/logs')
        ->assertForbidden();
});

test('el historial sin token responde 401', function () {
    $this->getJson('/admin/mail/logs')->assertUnauthorized();
});

test('el historial filtra por estado', function () {
    mailRegistro(['status' => MailLog::STATUS_SENT]);
    $fallido = mailRegistro(['status' => MailLog::STATUS_FAILED, 'to_email' => 'fallo@ejemplo.com']);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/logs?status=failed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $fallido->id);
});

test('el historial busca por parte de la dirección del destinatario', function () {
    mailRegistro(['to_email' => 'ana@ejemplo.com']);
    $luis = mailRegistro(['to_email' => 'luis@otra.com']);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/logs?to_email=luis')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $luis->id);
});

test('un registro del historial se consulta por id', function () {
    $registro = mailRegistro();

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson("/admin/mail/logs/{$registro->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $registro->id);
});

test('un registro del historial inexistente responde 404', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/logs/999999')
        ->assertNotFound();
});

test('las estadísticas cuentan los envíos por estado', function () {
    mailRegistro(['status' => MailLog::STATUS_SENT]);
    mailRegistro(['status' => MailLog::STATUS_SENT]);
    mailRegistro(['status' => MailLog::STATUS_BOUNCED]);

    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.logs')))->getJson('/admin/mail/logs/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_status.sent', 2)
        ->assertJsonPath('data.by_status.bounced', 1);
});

test('las estadísticas sin mail.logs responden 403', function () {
    $this->withHeaders(mailCabeceras(mailUsuarioCon('mail.manage')))->getJson('/admin/mail/logs/stats')
        ->assertForbidden();
});
