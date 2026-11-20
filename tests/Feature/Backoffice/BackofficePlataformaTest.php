<?php

use App\Contracts\MaintenanceServiceInterface;
use App\Enums\TenantStatus;
use App\Filament\Pages\Maintenance;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Filament\Resources\Data\DataExportResource;
use App\Filament\Resources\Data\DataImportResource;
use App\Filament\Resources\Data\Pages\ListDataExports;
use App\Filament\Resources\Data\Pages\ListDataImports;
use App\Filament\Resources\Data\Pages\ListPersonalDataExports;
use App\Filament\Resources\FeatureFlags\Pages\CreateFeatureFlag;
use App\Filament\Resources\FeatureFlags\Pages\EditFeatureFlag;
use App\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Filament\Resources\FeatureFlags\RelationManagers\TenantOverridesRelationManager;
use App\Filament\Resources\Mail\MailSuppressions\Pages\CreateMailSuppression;
use App\Filament\Resources\Mail\MailSuppressions\Pages\ListMailSuppressions;
use App\Filament\Resources\Privacy\LegalDocuments\LegalDocumentResource;
use App\Filament\Resources\Privacy\LegalDocuments\Pages\CreateLegalDocument;
use App\Filament\Resources\Privacy\LegalDocuments\Pages\ListLegalDocuments;
use App\Filament\Resources\Privacy\LegalDocuments\Pages\ViewLegalDocument;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager;
use App\Filament\Support\Pretty;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Models\LegalDocument;
use App\Models\Mail\MailSuppression;
use App\Models\PersonalDataExport;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantLimitOverride;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\InteractsWithTenancy;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    prepareBackoffice();
    $this->enableTenancy();
});

/** Usuario que entra al panel solo con los permisos dados. */
function conPermisosBo(string ...$permisos): User
{
    return backofficeUser('user', true, ['backoffice.access', ...$permisos]);
}

/** Invitación de un tenant, creada dentro de su contexto (el modelo es fail-closed). */
function invitarEnTenant(Tenant $tenant, string $email): TenantInvitation
{
    return app(TenantContext::class)->run($tenant, fn () => TenantInvitation::create([
        'email' => $email,
        'role' => 'tenant-member',
        'token_hash' => TenantInvitation::hashToken($email),
        'expires_at' => now()->addDays(3),
    ]));
}

// ---------- Acceso ----------

test('cada recurso nuevo niega el acceso a quien solo tiene backoffice.access', function (string $pagina) {
    $this->actingAs(conPermisosBo());

    Livewire::test($pagina)->assertForbidden();
})->with([
    'empresas' => ListTenants::class,
    'auditoría' => ListAuditLogs::class,
    'feature flags' => ListFeatureFlags::class,
    'supresión de correo' => ListMailSuppressions::class,
    'documentos legales' => ListLegalDocuments::class,
    'exportaciones' => ListDataExports::class,
    'importaciones' => ListDataImports::class,
    'datos personales' => ListPersonalDataExports::class,
    'mantenimiento' => Maintenance::class,
]);

test('sin MFA configurado el panel redirige antes de mostrar los recursos nuevos', function () {
    $user = backofficeUser('admin', mfa: false);

    $this->actingAs($user)->get('/backoffice/tenants')
        ->assertRedirect(route('filament.backoffice.auth.multi-factor-authentication.set-up-required'));
});

test('sin backoffice.access el panel devuelve 403 aunque se tenga el permiso del recurso', function () {
    $this->actingAs(backofficeUser('user', true, ['tenants.read']))->get('/backoffice/tenants')->assertForbidden();
});

// ---------- Empresas ----------

test('lista y busca empresas con tenants.read', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');

    $this->actingAs(conPermisosBo('tenants.read'));

    Livewire::test(ListTenants::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->searchTable('acme')
        ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

test('la ficha de empresa muestra sus miembros y no los de otra', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $enA = User::factory()->create();
    $enB = User::factory()->create();
    $this->addMember($a, $enA);
    $this->addMember($b, $enB);

    $this->actingAs(conPermisosBo('tenants.read'));

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $a, 'pageClass' => ViewTenant::class])
        ->assertCanSeeTableRecords([$enA])
        ->assertCanNotSeeTableRecords([$enB]);
});

test('las invitaciones de la ficha son solo las de esa empresa y no enseñan el token', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $deA = invitarEnTenant($a, 'uno@a.test');
    $deB = invitarEnTenant($b, 'dos@b.test');

    $this->actingAs(conPermisosBo('tenants.read'));

    Livewire::test(InvitationsRelationManager::class, ['ownerRecord' => $a, 'pageClass' => ViewTenant::class])
        ->assertCanSeeTableRecords([$deA])
        ->assertCanNotSeeTableRecords([$deB])
        ->assertTableColumnStateSet('estado', 'pendiente', $deA);

    expect(array_keys($deA->toArray()))->not->toContain('token_hash');
});

test('un administrador suspende y reactiva una empresa con motivo auditado', function () {
    $tenant = $this->makeTenant('acme');
    $admin = backofficeUser('admin');
    $this->actingAs($admin);

    Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
        ->callAction('suspend', ['reason' => 'Impago reiterado'])
        ->assertNotified();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    $entrada = AuditLog::where('event', 'tenant.suspend')->firstOrFail();
    expect($entrada->reason)->toBe('Impago reiterado')
        ->and($entrada->tenant_id)->toBe($tenant->id)
        ->and($entrada->auditable_id)->toBe((string) $tenant->id);

    Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
        ->assertActionHidden('suspend')
        ->callAction('reactivate', ['reason' => 'Pago recibido']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(AuditLog::where('event', 'tenant.reactivate')->value('reason'))->toBe('Pago recibido');
});

test('suspender exige motivo y no cambia nada sin él', function () {
    $tenant = $this->makeTenant('acme');
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
        ->callAction('suspend', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(AuditLog::where('event', 'tenant.suspend')->exists())->toBeFalse();
});

test('quien solo lee empresas no puede suspenderlas', function () {
    $tenant = $this->makeTenant('acme');
    $this->actingAs(conPermisosBo('tenants.read'));

    Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
        ->assertActionHidden('suspend')
        ->assertActionHidden('limit')
        ->assertActionHidden('plan');

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

test('con tenants.manage se suspende; reactivar una empresa activa se rechaza sin tocar nada', function () {
    $tenant = $this->makeTenant('acme');
    $this->actingAs(conPermisosBo('tenants.read', 'tenants.manage'));

    Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
        ->assertActionHidden('reactivate')
        ->callAction('suspend', ['reason' => 'Abuso']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

test('el override de un límite pasa por PlanLimits, queda auditado y es solo de esa empresa', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $this->actingAs(conPermisosBo('tenants.read', 'tenants.manage', 'plan-limits.manage'));

    Livewire::test(ViewTenant::class, ['record' => $a->getKey()])
        ->callAction('limit', ['key' => 'max_members', 'unlimited' => false, 'value' => 50, 'reason' => 'Acuerdo comercial'])
        ->assertNotified();

    expect(TenantLimitOverride::where('tenant_id', $a->id)->where('key', 'max_members')->value('value'))->toBe(50)
        ->and(TenantLimitOverride::where('tenant_id', $b->id)->exists())->toBeFalse()
        ->and(AuditLog::where('event', 'tenant.limit-override')->where('tenant_id', $a->id)->value('reason'))->toBe('Acuerdo comercial');

    Livewire::test(ViewTenant::class, ['record' => $a->getKey()])
        ->callAction('plan', ['plan' => 'pro']);

    expect($a->fresh()->plan)->toBe('pro')->and($b->fresh()->plan)->toBeNull();
});

// ---------- Auditoría ----------

test('la auditoría es de solo lectura, se filtra por empresa y evento y redacta secretos', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $deA = AuditLog::create(['tenant_id' => $a->id, 'event' => 'user.block', 'actor_type' => 'user', 'actor_id' => '1', 'new_values' => ['password' => 'secreta-en-claro', 'ok' => 'si']]);
    $deB = AuditLog::create(['tenant_id' => $b->id, 'event' => 'user.unblock', 'actor_type' => 'user', 'actor_id' => '2']);

    $this->actingAs(conPermisosBo('audit.read'));

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$deA, $deB])
        ->filterTable('tenant_id', ['tenant_id' => $a->id])
        ->assertCanSeeTableRecords([$deA])->assertCanNotSeeTableRecords([$deB])
        ->resetTableFilters()
        ->filterTable('event', ['event' => 'user.unblock'])
        ->assertCanSeeTableRecords([$deB])->assertCanNotSeeTableRecords([$deA]);

    $entrada = Pretty::entry('new_values', 'Después');
    Schema::make()->record($deA)->components([$entrada])->getComponents();

    expect((string) $entrada->getState())->toContain('si')->not->toContain('secreta-en-claro')
        ->and(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($deA))->toBeFalse()
        ->and(AuditLogResource::canDelete($deA))->toBeFalse();
});

// ---------- Feature flags ----------

test('crear una feature flag escribe por el servicio y solo admite claves declaradas', function () {
    $this->actingAs(conPermisosBo('feature-flags.read', 'feature-flags.manage'));

    Livewire::test(CreateFeatureFlag::class)
        ->fillForm(['key' => 'advanced_search', 'enabled' => true, 'rollout_percentage' => 40, 'rollout_by' => 'tenant'])
        ->call('create')
        ->assertHasNoFormErrors();

    $flag = FeatureFlag::where('key', 'advanced_search')->firstOrFail();
    expect($flag->enabled)->toBeTrue()->and($flag->rollout_percentage)->toBe(40)->and($flag->rollout_by)->toBe('tenant');

    Livewire::test(CreateFeatureFlag::class)
        ->fillForm(['key' => 'no_declarada', 'enabled' => true, 'rollout_percentage' => 100, 'rollout_by' => 'user'])
        ->call('create')
        ->assertHasFormErrors(['key']);

    expect(FeatureFlag::where('key', 'no_declarada')->exists())->toBeFalse();
});

test('sin feature-flags.manage no se crean flags aunque se lean', function () {
    $this->actingAs(conPermisosBo('feature-flags.read'));

    Livewire::test(ListFeatureFlags::class)->assertOk();
    Livewire::test(CreateFeatureFlag::class)->assertForbidden();
});

test('los overrides por empresa de una flag se fijan y se quitan por el servicio, sin mezclar empresas', function () {
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $flag = FeatureFlag::create(['key' => 'advanced_search', 'enabled' => false, 'rollout_percentage' => 100, 'rollout_by' => 'user']);
    FeatureFlagOverride::create(['feature_key' => 'advanced_search', 'tenant_id' => $b->id, 'enabled' => true]);

    $this->actingAs(conPermisosBo('feature-flags.read', 'feature-flags.manage'));

    $manager = Livewire::test(TenantOverridesRelationManager::class, ['ownerRecord' => $flag, 'pageClass' => EditFeatureFlag::class]);
    $manager->callAction(TestAction::make('create')->table(), ['tenant_id' => $a->id, 'enabled' => true])->assertNotified();

    expect(FeatureFlagOverride::where('tenant_id', $a->id)->value('enabled'))->toBeTrue();

    $deA = FeatureFlagOverride::where('tenant_id', $a->id)->firstOrFail();
    $manager->callTableAction('clear', $deA);

    expect(FeatureFlagOverride::where('tenant_id', $a->id)->exists())->toBeFalse()
        ->and(FeatureFlagOverride::where('tenant_id', $b->id)->exists())->toBeTrue();
});

// ---------- Supresión de correo ----------

test('añadir una supresión manual la guarda por el servicio', function () {
    $this->actingAs(conPermisosBo('mail.suppressions'));

    Livewire::test(CreateMailSuppression::class)
        ->fillForm(['email' => 'Rebote@Ejemplo.TEST'])
        ->call('create')
        ->assertHasNoFormErrors();

    $s = MailSuppression::where('email', 'rebote@ejemplo.test')->firstOrFail();
    expect($s->reason)->toBe('manual')->and($s->source)->toBe('backoffice');
});

test('quitar una supresión exige motivo y queda auditado', function () {
    $s = MailSuppression::create(['email' => 'a@b.test', 'reason' => 'bounce', 'source' => 'resend', 'suppressed_at' => now()]);
    $this->actingAs(conPermisosBo('mail.suppressions'));

    Livewire::test(ListMailSuppressions::class)
        ->assertCanSeeTableRecords([$s])
        ->callTableAction('release', $s, ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect(MailSuppression::count())->toBe(1);

    Livewire::test(ListMailSuppressions::class)->callTableAction('release', $s, ['reason' => 'El cliente corrigió su buzón']);

    expect(MailSuppression::count())->toBe(0)
        ->and(AuditLog::where('event', 'mail-suppression.release')->value('reason'))->toBe('El cliente corrigió su buzón');
});

test('sin mail.suppressions no se ve ni se gestiona la lista de supresión', function () {
    $this->actingAs(conPermisosBo('mail.logs'));

    Livewire::test(ListMailSuppressions::class)->assertForbidden();
    Livewire::test(CreateMailSuppression::class)->assertForbidden();
});

// ---------- Documentos legales ----------

test('publicar un documento legal crea una versión inmutable y la ficha agrega las aceptaciones', function () {
    $this->actingAs(conPermisosBo('legal-documents.read', 'legal-documents.manage'));

    Livewire::test(CreateLegalDocument::class)
        ->fillForm(['key' => 'terms', 'version' => '2', 'locale' => 'es', 'title' => 'Términos', 'body' => 'Texto completo'])
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = LegalDocument::where('key', 'terms')->firstOrFail();
    expect($doc->content_hash)->toBe(hash('sha256', "Términos\nTexto completo"));

    // Misma versión e idioma: se rechaza (los documentos no se pisan).
    Livewire::test(CreateLegalDocument::class)
        ->fillForm(['key' => 'terms', 'version' => '2', 'locale' => 'es', 'title' => 'Otra', 'body' => 'Otro'])
        ->call('create')
        ->assertHasFormErrors(['locale']);

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    foreach ([$u1, $u2] as $u) {
        UserConsent::create(['user_id' => $u->id, 'document_key' => 'terms', 'version' => '2', 'action' => 'accepted']);
    }
    UserConsent::create(['user_id' => $u2->id, 'document_key' => 'terms', 'version' => '2', 'action' => 'withdrawn']);
    UserConsent::create(['user_id' => $u1->id, 'document_key' => 'terms', 'version' => '1', 'action' => 'accepted']);

    Livewire::test(ViewLegalDocument::class, ['record' => $doc->getKey()])
        ->assertSchemaStateSet(['accepted' => 2, 'withdrawn' => 1]);

    expect(LegalDocumentResource::canEdit($doc))->toBeFalse()
        ->and(LegalDocumentResource::canDelete($doc))->toBeFalse();
});

test('sin legal-documents.manage no se publica; con read solo se consulta', function () {
    $doc = LegalDocument::create(['key' => 'terms', 'version' => '1', 'locale' => 'es', 'title' => 'T', 'body' => 'B', 'content_hash' => hash('sha256', 'x'), 'effective_at' => now()]);
    $this->actingAs(conPermisosBo('legal-documents.read'));

    Livewire::test(ListLegalDocuments::class)->assertCanSeeTableRecords([$doc]);
    Livewire::test(CreateLegalDocument::class)->assertForbidden();
});

// ---------- Mantenimiento ----------

test('el mantenimiento se activa y se desactiva por el servicio con rastro de auditoría', function () {
    Cache::flush();
    $this->actingAs(conPermisosBo('maintenance.manage'));

    Livewire::test(Maintenance::class)
        ->assertActionVisible('enable')->assertActionHidden('disable')
        ->callAction('enable', ['message' => 'Actualizando la base de datos'])
        ->assertNotified();

    expect(app(MaintenanceServiceInterface::class)->state()['message'])->toBe('Actualizando la base de datos')
        ->and(AuditLog::where('event', 'maintenance.enable')->exists())->toBeTrue();

    Livewire::test(Maintenance::class)
        ->assertActionHidden('enable')->assertActionVisible('disable')
        ->callAction('disable');

    expect(app(MaintenanceServiceInterface::class)->state())->toBeNull()
        ->and(AuditLog::where('event', 'maintenance.disable')->exists())->toBeTrue();
});

// ---------- Exportaciones e importaciones ----------

test('exportaciones, importaciones y datos personales son de solo lectura y piden su permiso', function () {
    $user = User::factory()->create();
    $export = DataExport::create(['user_id' => $user->id, 'exporter' => 'users', 'format' => 'csv', 'status' => 'completed', 'disk' => 'local', 'path' => 'secreto/ruta.csv']);
    $import = DataImport::create(['user_id' => $user->id, 'importer' => 'users', 'status' => 'completed', 'original_name' => 'u.csv', 'disk' => 'local', 'path' => 'x/y.csv', 'file_hash' => str_repeat('a', 64)]);
    $personal = PersonalDataExport::create(['user_id' => $user->id, 'status' => 'ready', 'disk' => 'local', 'path' => 'privado/zip']);

    $this->actingAs(conPermisosBo());
    Livewire::test(ListDataExports::class)->assertForbidden();
    Livewire::test(ListDataImports::class)->assertForbidden();
    Livewire::test(ListPersonalDataExports::class)->assertForbidden();

    $this->actingAs(conPermisosBo('users.export', 'users.import'));
    Livewire::test(ListDataExports::class)->assertCanSeeTableRecords([$export]);
    Livewire::test(ListDataImports::class)->assertCanSeeTableRecords([$import]);
    Livewire::test(ListPersonalDataExports::class)->assertCanSeeTableRecords([$personal]);

    expect(DataExportResource::canCreate())->toBeFalse()
        ->and(DataExportResource::canDelete($export))->toBeFalse()
        ->and(DataImportResource::canEdit($import))->toBeFalse();
});
