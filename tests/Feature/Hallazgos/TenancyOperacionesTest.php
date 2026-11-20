<?php

use App\Contracts\TenantMemberServiceInterface;
use App\Contracts\TenantOnboardingServiceInterface;
use App\Contracts\WebhookServiceInterface;
use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\TenantInactiveForJob;
use App\Jobs\SendWebhookJob;
use App\Listeners\RestoreTenantForJob;
use App\Models\File;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PermissionTeamsSchema;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);
});

// ── 8. Tope de empresas por usuario bajo bloqueo ────────────────────────────

test('el alta de empresa cuenta el tope dentro de la transaccion con la fila del usuario bloqueada', function () {
    config(['multi-tenancy.onboarding.signup' => ['enabled' => true, 'max_tenants_per_user' => 1]]);
    $user = User::factory()->create();
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = [strtolower($q->sql), DB::transactionLevel()];
    });

    app(TenantOnboardingServiceInterface::class)->createTenant($user, ['name' => 'Una', 'slug' => 'una-corp']);

    $bloqueo = array_values(array_filter($sql, fn ($s) => str_contains($s[0], 'from "users"') && str_contains($s[0], 'for update')));
    $conteo = array_values(array_filter($sql, fn ($s) => str_contains($s[0], 'from "tenant_user"') && str_contains($s[0], 'count(')));

    expect($bloqueo)->not->toBeEmpty()->and($conteo)->not->toBeEmpty()
        // baseline del test = 1; la transaccion del servicio = 2
        ->and($conteo[0][1])->toBeGreaterThanOrEqual(2);
});

// ── 9. tenancy:assign-orphans ───────────────────────────────────────────────

test('assign-orphans asigna solo las filas de miembros activos del tenant destino', function () {
    config(['multi-tenancy.enabled' => false]);
    $miembro = User::factory()->create();
    $ajeno = User::factory()->create();
    $propio = File::factory()->create(['user_id' => $miembro->id]);
    $deAjeno = File::factory()->create(['user_id' => $ajeno->id]);
    config(['multi-tenancy.enabled' => true]);
    $this->addMember($this->a, $miembro);

    Artisan::call('tenancy:assign-orphans', ['tenant' => 'acme']);

    expect(DB::table('files')->where('id', $propio->id)->value('tenant_id'))->toBe($this->a->id)
        ->and(DB::table('files')->where('id', $deAjeno->id)->value('tenant_id'))->toBeNull();
});

test('assign-orphans con dry-run lista por dueño y no cambia nada; --user exige membresia', function () {
    config(['multi-tenancy.enabled' => false]);
    $miembro = User::factory()->create();
    $ajeno = User::factory()->create();
    $archivo = File::factory()->create(['user_id' => $miembro->id]);
    config(['multi-tenancy.enabled' => true]);
    $this->addMember($this->a, $miembro);

    Artisan::call('tenancy:assign-orphans', ['tenant' => 'acme', '--dry-run' => true]);

    expect(Artisan::output())->toContain("usuario {$miembro->id}: 1")
        ->and(DB::table('files')->where('id', $archivo->id)->value('tenant_id'))->toBeNull();

    expect(Artisan::call('tenancy:assign-orphans', ['tenant' => 'acme', '--user' => $ajeno->id]))->toBe(1);
});

// ── 10. Rol del miembro sincronizado dentro de la transaccion ───────────────

test('cambiar el rol de un miembro sincroniza el rol de Spatie dentro de la transaccion', function () {
    $dueno = User::factory()->create();
    $miembro = User::factory()->create();
    $this->addMember($this->a, $dueno);
    DB::table('tenant_user')->where('user_id', $dueno->id)->update(['role' => 'owner']);
    $this->addMember($this->a, $miembro);
    DB::table('tenant_user')->where('user_id', $miembro->id)->update(['role' => 'member']);

    $niveles = [];
    DB::listen(function ($q) use (&$niveles) {
        if (str_contains(strtolower($q->sql), 'insert into "model_has_roles"')) {
            $niveles[] = DB::transactionLevel();
        }
    });

    app(TenantMemberServiceInterface::class)->changeRole($this->a, $dueno, $miembro->id, 'admin');

    expect($niveles)->not->toBeEmpty()->and(min($niveles))->toBeGreaterThanOrEqual(2);
});

// ── 12. Jobs de tenants no activos ──────────────────────────────────────────

test('un job de un tenant suspendido falla en vez de ejecutarse', function () {
    $this->a->update(['status' => TenantStatus::Suspended]);
    Context::addHidden(TenantContext::CONTEXT_KEY, $this->a->id);

    expect(fn () => (new RestoreTenantForJob)->handle(new JobProcessing('redis', Mockery::mock(Job::class))))
        ->toThrow(TenantInactiveForJob::class);
});

test('un job de un tenant activo restaura su contexto', function () {
    Context::addHidden(TenantContext::CONTEXT_KEY, $this->a->id);

    (new RestoreTenantForJob)->handle(new JobProcessing('redis', Mockery::mock(Job::class)));

    expect($this->ctx->id())->toBe($this->a->id);
});

test('la entrega de un webhook de un tenant pendiente se descarta sin tocar nada', function () {
    $job = $this->ctx->run($this->a, fn () => new SendWebhookJob(999, 999));
    $this->a->update(['status' => TenantStatus::Pending]);
    $this->ctx->clear();

    // Sin el descarte lanzaria RuntimeException ("Webhook o entrega no encontrada").
    $job->process(Mockery::mock(WebhookServiceInterface::class)->shouldNotReceive('deliver')->getMock());

    expect(true)->toBeTrue();
});

// ── 11. Resincronizar roles tenant-* al activar equipos ─────────────────────

test('al activar equipos los roles tenant-* se rehacen por tenant desde tenant_user.role', function () {
    $olvidar = fn () => (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
    $usuario = User::factory()->create();
    $this->addMember($this->a, $usuario);
    DB::table('tenant_user')->where('user_id', $usuario->id)->update(['role' => 'admin']);
    $this->addMember($this->b, $usuario);
    DB::table('tenant_user')->where('tenant_id', $this->b->id)->where('user_id', $usuario->id)->update(['role' => 'member']);

    try {
        // Antes de equipos: el rol tenant-* es global (un solo tenant-admin sirve en todas las empresas).
        $usuario->assignRole(Role::where('name', 'tenant-admin')->first());

        PermissionTeamsSchema::up();
        $olvidar();
        config(['permission.teams' => true]);
        app(PermissionRegistrar::class)->initializeCache();

        expect(PermissionTeamsSchema::resyncTenantRoles())->toBe(2);

        $enEquipo = fn (int $team) => DB::table('model_has_roles')->where('model_has_roles.model_id', $usuario->id)->where('model_has_roles.tenant_id', $team)
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->pluck('roles.name')->all();

        expect($enEquipo($this->a->id))->toContain('tenant-admin')
            ->and($enEquipo(PermissionTeamsSchema::PLATFORM_TEAM))->not->toContain('tenant-admin')
            ->and($enEquipo($this->b->id))->not->toContain('tenant-admin');
    } finally {
        $olvidar();
        config(['permission.teams' => false]);
        app(PermissionRegistrar::class)->initializeCache();
    }
});

test('resyncTenantRoles exige equipos activos', function () {
    expect(fn () => PermissionTeamsSchema::resyncTenantRoles())->toThrow(LogicException::class);
});
