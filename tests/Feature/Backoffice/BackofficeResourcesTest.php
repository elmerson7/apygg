<?php

use App\Filament\Resources\Logs\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Logs\ApiLogs\ApiLogResource;
use App\Filament\Resources\Logs\ApiLogs\Pages\ListApiLogs;
use App\Filament\Resources\Logs\SecurityLogs\Pages\ListSecurityLogs;
use App\Filament\Resources\Mail\MailLogs\Pages\ListMailLogs;
use App\Filament\Resources\Mail\MailTemplates\Pages\ListMailTemplates;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\System\Blacklists\Pages\CreateBlacklist;
use App\Filament\Resources\System\Blacklists\Pages\ListBlacklists;
use App\Filament\Resources\System\Settings\Pages\CreateSettings;
use App\Filament\Resources\System\Settings\Pages\ListSettings;
use App\Filament\Support\Pretty;
use App\Filament\Widgets\HealthStatusWidget;
use App\Models\Blacklist;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailTemplateConfig;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Settings;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => prepareBackoffice());

// ---------- Logs ----------

test('un log de la API no muestra secretos aunque estén guardados sin redactar', function () {
    $log = ApiLog::factory()->create([
        'request_body' => ['email' => 'a@b.com', 'password' => 'hunter2-secreta', 'nested' => ['api_key' => 'sk-live-123']],
        'request_headers' => ['authorization' => 'Bearer token-super-secreto'],
        'response_body' => ['access_token' => 'jwt-privado'],
    ]);

    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ListApiLogs::class)->assertCanSeeTableRecords([$log]);

    // Lo que muestra la ficha: el estado de cada entrada JSON del infolist, ya redactado.
    $mostrado = collect(['request_query', 'request_body', 'request_headers', 'response_body'])
        ->map(function (string $campo) use ($log): string {
            $entrada = Pretty::entry($campo, $campo);
            Schema::make()->record($log)->components([$entrada])->getComponents();

            return $entrada->getState();
        })
        ->implode("\n");

    expect($mostrado)->toContain('a@b.com')
        ->toContain('[REDACTED]')
        ->not->toContain('hunter2-secreta')
        ->not->toContain('sk-live-123')
        ->not->toContain('token-super-secreto')
        ->not->toContain('jwt-privado');
});

test('un log de seguridad y uno de actividad tampoco muestran secretos', function () {
    $seguridad = SecurityLog::factory()->create(['details' => ['password' => 'clave-en-claro', 'motivo' => 'login']]);
    $actividad = ActivityLog::factory()->create(['old_values' => ['password' => 'vieja-clave'], 'new_values' => ['app_authentication_secret' => 'TOTPSECRETO']]);

    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ListSecurityLogs::class)->assertCanSeeTableRecords([$seguridad]);
    Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$actividad]);

    // Lo que muestra la ficha: el estado de cada entrada JSON del infolist, ya redactado
    // (igual que el test del log de la API; no depende de que el modal llegue a pintarse).
    $estado = function ($registro, string $campo): string {
        $entrada = Pretty::entry($campo, $campo);
        Schema::make()->record($registro)->components([$entrada])->getComponents();

        return (string) $entrada->getState();
    };

    expect($estado($seguridad, 'details'))->toContain('login')->not->toContain('clave-en-claro')
        ->and($estado($actividad, 'old_values'))->not->toContain('vieja-clave')
        ->and($estado($actividad, 'new_values'))->not->toContain('TOTPSECRETO');
});

test('los logs son de solo lectura y piden system.logs', function () {
    $log = ApiLog::factory()->create();

    $this->actingAs(backofficeUser('user', true, ['backoffice.access']));
    Livewire::test(ListApiLogs::class)->assertForbidden();

    $this->actingAs(backofficeUser('user', true, ['backoffice.access', 'system.logs']));
    Livewire::test(ListApiLogs::class)->assertOk()->assertCanSeeTableRecords([$log]);

    expect(ApiLogResource::canCreate())->toBeFalse()
        ->and(ApiLogResource::canEdit($log))->toBeFalse()
        ->and(ApiLogResource::canDelete($log))->toBeFalse();
});

test('el log de API se filtra por método y por clase de estado', function () {
    $get = ApiLog::factory()->create(['request_method' => 'GET', 'response_status' => 200]);
    $post = ApiLog::factory()->create(['request_method' => 'POST', 'response_status' => 500]);

    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ListApiLogs::class)
        ->filterTable('request_method', 'POST')
        ->assertCanSeeTableRecords([$post])->assertCanNotSeeTableRecords([$get])
        ->resetTableFilters()
        ->filterTable('status_class', '2')
        ->assertCanSeeTableRecords([$get])->assertCanNotSeeTableRecords([$post]);
});

// ---------- Correo ----------

test('un administrador pausa y reanuda una plantilla de correo', function () {
    MailTemplateConfig::seedDefaults();
    $plantilla = MailTemplateConfig::where('template_key', 'welcome')->firstOrFail();

    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ListMailTemplates::class)->callTableAction('pause', $plantilla);
    expect($plantilla->fresh()->is_active)->toBeFalse()
        ->and(MailTemplateConfig::isPaused('welcome'))->toBeTrue();

    Livewire::test(ListMailTemplates::class)->callTableAction('resume', $plantilla);
    expect($plantilla->fresh()->is_active)->toBeTrue();
});

test('sin mail.manage no se ven las plantillas y sin mail.logs no se ve el registro de envíos', function () {
    $this->actingAs(backofficeUser('user', true, ['backoffice.access']));

    Livewire::test(ListMailTemplates::class)->assertForbidden();
    Livewire::test(ListMailLogs::class)->assertForbidden();
});

test('mail.logs permite ver el registro de envíos sin dar acceso a las plantillas', function () {
    $envio = MailLog::create(['to_email' => 'cliente@example.com', 'subject' => 'Hola', 'status' => MailLog::STATUS_SENT]);

    $this->actingAs(backofficeUser('user', true, ['backoffice.access', 'mail.logs']));

    Livewire::test(ListMailLogs::class)->assertCanSeeTableRecords([$envio]);
    Livewire::test(ListMailTemplates::class)->assertForbidden();
});

// ---------- Roles y permisos ----------

test('un administrador crea un rol con permisos desde el panel', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'auditor',
            'display_name' => 'Auditor',
            'permission_ids' => [Permission::where('name', 'system.logs')->value('id')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Role::where('name', 'auditor')->first()->hasPermissionTo('system.logs'))->toBeTrue();
});

test('el panel no deja renombrar el rol admin', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(EditRole::class, ['record' => roleId('admin')])
        ->assertFormFieldDisabled('name');
});

test('un gerente con roles.manage-permissions no concede permisos que no tiene', function () {
    $manager = backofficeManager(['roles.manage-permissions']);
    $logs = Permission::where('name', 'system.logs')->value('id');

    $this->actingAs($manager);

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'espia', 'display_name' => 'Espía', 'permission_ids' => [$logs]])
        ->call('create');

    expect(Role::where('name', 'espia')->exists())->toBeFalse();
});

test('un gerente sin roles.manage-permissions no puede cambiar los permisos de un rol', function () {
    $manager = backofficeManager(['roles.update']);
    $rol = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
    $rol->givePermissionTo('users.read');

    $this->actingAs($manager);

    Livewire::test(EditRole::class, ['record' => $rol->getKey()])
        ->assertFormFieldDisabled('permission_ids')
        ->fillForm(['display_name' => 'Editor 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rol->fresh()->display_name)->toBe('Editor 2')
        ->and($rol->fresh()->permissions->pluck('name')->all())->toBe(['users.read']);
});

test('un gerente no puede editar el rol admin', function () {
    $this->actingAs(backofficeManager(['roles.update']));

    Livewire::test(EditRole::class, ['record' => roleId('admin')])->assertForbidden();
});

test('los permisos solo se listan: no se pueden crear, editar ni borrar', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ListPermissions::class)->assertOk()
        ->assertCanSeeTableRecords(Permission::where('name', 'users.read')->get());

    expect(PermissionResource::canCreate())->toBeFalse()
        ->and(PermissionResource::canEdit(Permission::first()))->toBeFalse()
        ->and(PermissionResource::canDelete(Permission::first()))->toBeFalse();
});

test('el listado de roles lo ve quien tiene roles.read', function () {
    $this->actingAs(backofficeManager());

    Livewire::test(ListRoles::class)->assertOk()->assertCanSeeTableRecords(Role::all());
});

// ---------- Ajustes y lista negra ----------

test('un administrador crea un ajuste cifrado y su valor no se muestra', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(CreateSettings::class)
        ->fillForm(['key' => 'integracion.token', 'value' => 'valor-ultra-secreto', 'type' => 'string', 'is_encrypted' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $ajuste = Settings::where('key', 'integracion.token')->firstOrFail();

    expect($ajuste->value)->not->toBe('valor-ultra-secreto');

    Livewire::test(ListSettings::class)
        ->assertCanSeeTableRecords([$ajuste])
        ->assertDontSee('valor-ultra-secreto');
});

test('los ajustes los gestiona solo un administrador', function () {
    $this->actingAs(backofficeManager(['system.settings']));

    Livewire::test(ListSettings::class)->assertForbidden();
});

test('con security.blacklist se bloquea una identidad y queda quién la bloqueó', function () {
    $gerente = backofficeUser('user', true, ['backoffice.access', 'security.blacklist']);

    $this->actingAs($gerente);

    Livewire::test(CreateBlacklist::class)
        ->fillForm(['email' => 'estafador@example.com', 'reason' => 'Contracargos'])
        ->call('create')
        ->assertHasNoFormErrors();

    $bloqueo = Blacklist::firstOrFail();

    expect($bloqueo->added_by)->toBe($gerente->id)
        ->and(Blacklist::isBlocked('estafador@example.com'))->toBeTrue();

    Livewire::test(ListBlacklists::class)->callTableAction('delete', $bloqueo);
    expect(Blacklist::count())->toBe(0);
});

test('sin security.blacklist no se accede a la lista negra', function () {
    $this->actingAs(backofficeUser('user', true, ['backoffice.access']));

    Livewire::test(ListBlacklists::class)->assertForbidden();
});

// ---------- Dashboard ----------

test('el widget de salud se renderiza reutilizando el chequeo de la API', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(HealthStatusWidget::class)->assertSee('Database');
});
