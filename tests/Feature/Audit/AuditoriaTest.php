<?php

use App\Contracts\AuditServiceInterface;
use App\Exceptions\AuditException;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Blacklist;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    $this->ownerA = $this->isoUser($this->a, 'owner');
    $this->adminA = $this->isoUser($this->a, 'admin');
    $this->memberA = $this->isoUser($this->a, 'member');
    $this->plataforma = User::factory()->admin()->create();
});

function auditoriaWebhook($test, $tenant, array $extra = []): Webhook
{
    return $test->ctx->run($tenant, fn () => Webhook::factory()->create($extra + ['user_id' => $test->ownerA->id, 'name' => 'hook', 'status' => 'active', 'events' => null, 'url' => 'https://example.com/h', 'secret' => str_repeat('s', 40)]));
}

// ── Trait Auditable ────────────────────────────────────────────────────────

test('crear un modelo auditable guarda la entrada con tenant, actor sistema y sin secretos', function () {
    $webhook = auditoriaWebhook($this, $this->a);

    $log = AuditLog::query()->where('auditable_type', $webhook->getMorphClass())->where('event', 'created')->firstOrFail();

    expect($log->tenant_id)->toBe($this->a->id)
        ->and($log->auditable_id)->toBe((string) $webhook->id)
        ->and($log->actor_type)->toBe('system')
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values['name'])->toBe('hook')
        ->and($log->new_values)->not->toHaveKeys(['secret', 'old_secret']);
});

test('actualizar guarda solo los campos que cambiaron, con antes y después', function () {
    $webhook = auditoriaWebhook($this, $this->a);

    $this->ctx->run($this->a, fn () => $webhook->update(['name' => 'nuevo', 'timeout' => 99]));

    $log = AuditLog::query()->where('event', 'updated')->firstOrFail();

    expect(array_keys($log->new_values))->toEqualCanonicalizing(['name', 'timeout'])
        ->and($log->old_values['name'])->toBe('hook')
        ->and($log->new_values['name'])->toBe('nuevo')
        ->and($log->new_values['timeout'])->toBe(99);
});

test('un cambio solo de campos ocultos o ruidosos no genera entrada', function () {
    $webhook = auditoriaWebhook($this, $this->a);
    $antes = AuditLog::query()->count();

    $this->ctx->run($this->a, fn () => $webhook->update(['secret' => str_repeat('x', 40), 'last_triggered_at' => now()]));

    expect(AuditLog::query()->count())->toBe($antes);
});

test('borrar y restaurar quedan registrados', function () {
    $webhook = auditoriaWebhook($this, $this->a);

    $this->ctx->run($this->a, function () use ($webhook) {
        $webhook->delete();
        $webhook->restore();
    });

    expect(AuditLog::query()->where('auditable_id', (string) $webhook->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['created', 'deleted', 'restored']);
});

test('los secretos y correos dentro de un diff se redactan', function () {
    $tenant = $this->a;
    $tenant->update(['settings' => ['api_key' => 'sk_live_super_secreto', 'contacto' => 'persona@ejemplo.com', 'tema' => 'oscuro']]);

    $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', (string) $tenant->id)->latest('id')->firstOrFail();
    $json = json_encode($log->new_values);

    expect($json)->not->toContain('sk_live_super_secreto')->not->toContain('persona@ejemplo.com')
        ->and($log->new_values['settings']['tema'])->toBe('oscuro');
});

test('una API key auditada nunca guarda su hash y su uso no genera ruido', function () {
    $key = $this->ctx->run($this->a, fn () => ApiKey::factory()->create(['user_id' => $this->ownerA->id]));
    $antes = AuditLog::query()->count();

    $this->ctx->run($this->a, fn () => $key->update(['last_used_at' => now()]));

    $log = AuditLog::query()->where('auditable_type', $key->getMorphClass())->where('event', 'created')->firstOrFail();

    expect(AuditLog::query()->count())->toBe($antes)
        ->and($log->new_values)->not->toHaveKey('key');
});

test('el actor, la IP y el request_id salen de la petición', function () {
    $headers = $this->isoHeaders($this->ownerA, $this->a) + ['X-Request-ID' => '4f1c0e6a-2b7d-4c1e-9a55-0d3f6a7b8c91'];

    $this->isoCall($headers, 'POST', '/api-keys', ['name' => 'desde-api', 'scopes' => ['profile.read']])->assertCreated();

    $log = AuditLog::query()->where('auditable_type', (new ApiKey)->getMorphClass())->where('event', 'created')->latest('id')->firstOrFail();

    expect($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe((string) $this->ownerA->id)
        ->and($log->request_id)->toBe('4f1c0e6a-2b7d-4c1e-9a55-0d3f6a7b8c91')
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->tenant_id)->toBe($this->a->id);
});

test('con AUDIT_ENABLED apagado no se escribe nada', function () {
    $base = AuditLog::query()->count();
    config(['audit.enabled' => false]);

    auditoriaWebhook($this, $this->a);

    expect(AuditLog::query()->count())->toBe($base);
});

test('una entrada de auditoría no se puede modificar ni borrar desde la aplicación', function () {
    auditoriaWebhook($this, $this->a);
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => $log->update(['event' => 'x']))->toThrow(AuditException::class)
        ->and(fn () => $log->delete())->toThrow(AuditException::class);
});

// ── Miembros, invitaciones y tenant (auditados vía servicio) ──────────────

test('cambiar rol, suspender, reactivar y quitar a un miembro quedan auditados con el tenant', function () {
    $h = $this->isoHeaders($this->ownerA, $this->a);
    $id = $this->memberA->id;

    $this->isoCall($h, 'PATCH', "/tenant/members/{$id}", ['role' => 'admin'])->assertOk();
    $this->isoCall($h, 'POST', "/tenant/members/{$id}/suspend")->assertOk();
    $this->isoCall($h, 'POST', "/tenant/members/{$id}/reactivate")->assertOk();
    $this->isoCall($h, 'DELETE', "/tenant/members/{$id}")->assertOk();

    $eventos = AuditLog::query()->where('tenant_id', $this->a->id)->where('event', 'like', 'tenant.member-%')->orderBy('id')->pluck('event')->all();

    expect($eventos)->toBe(['tenant.member-role-changed', 'tenant.member-suspended', 'tenant.member-reactivated', 'tenant.member-removed']);

    $cambio = AuditLog::query()->where('event', 'tenant.member-role-changed')->firstOrFail();
    expect($cambio->actor_id)->toBe((string) $this->ownerA->id)
        ->and($cambio->new_values['role_after'])->toBe('admin');
});

test('crear una empresa y una invitación quedan auditadas', function () {
    $nuevo = User::factory()->create();
    config(['multi-tenancy.onboarding.signup.enabled' => true]);

    $this->isoCall(['Authorization' => $this->isoHeaders($nuevo, $this->a)['Authorization']], 'POST', '/tenants', ['name' => 'Gamma', 'slug' => 'gamma'])->assertCreated();

    $tenant = Tenant::query()->where('slug', 'gamma')->firstOrFail();
    $eventos = AuditLog::query()->where('tenant_id', $tenant->id)->pluck('event')->all();

    expect($eventos)->toContain('created', 'tenant.member-added');

    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'POST', '/tenant/invitations', ['email' => 'nuevo@ejemplo.com', 'role' => 'member'])->assertCreated();

    $inv = AuditLog::query()->where('tenant_id', $this->a->id)->where('auditable_type', 'like', '%TenantInvitation')->firstOrFail();
    expect($inv->event)->toBe('created')
        ->and(json_encode($inv->new_values))->not->toContain('token_hash');
});

// ── Acciones con motivo ────────────────────────────────────────────────────

test('cambiar roles de un usuario sin motivo responde 422 audit_reason_required y no cambia nada', function () {
    $objetivo = User::factory()->create();
    $rol = Role::where('name', 'manager')->value('id');
    $h = ['Authorization' => $this->isoHeaders($this->plataforma, $this->a)['Authorization']];

    $this->isoCall($h, 'POST', "/users/{$objetivo->id}/roles", ['role_ids' => [$rol]])
        ->assertStatus(422)->assertJsonPath('code', 'audit_reason_required');

    expect($objetivo->fresh()->hasRole('manager'))->toBeFalse();

    $this->isoCall($h, 'POST', "/users/{$objetivo->id}/roles", ['role_ids' => [$rol], 'reason' => 'Promoción a gerente'])->assertOk();

    $log = AuditLog::query()->where('event', 'user.roles-change')->firstOrFail();
    expect($log->reason)->toBe('Promoción a gerente')
        ->and($log->actor_id)->toBe((string) $this->plataforma->id)
        ->and($log->auditable_id)->toBe((string) $objetivo->id)
        ->and($log->new_values['role_ids_after'])->toBe([$rol]);
});

test('bloquear y desbloquear una identidad se audita con su motivo; desbloquear exige motivo', function () {
    $objetivo = User::factory()->create();
    $h = ['Authorization' => $this->isoHeaders($this->plataforma, $this->a)['Authorization']];

    $id = $this->isoCall($h, 'POST', '/admin/blacklist', ['user_id' => $objetivo->id, 'reason' => 'Fraude'])->assertCreated()->json('data.id');

    $this->isoCall($h, 'DELETE', "/admin/blacklist/{$id}")->assertStatus(422)->assertJsonPath('code', 'audit_reason_required');
    expect(Blacklist::query()->find($id))->not->toBeNull();

    $this->isoCall($h, 'DELETE', "/admin/blacklist/{$id}", ['reason' => 'Revisado'])->assertOk();

    expect(AuditLog::query()->where('event', 'user.block')->value('reason'))->toBe('Fraude')
        ->and(AuditLog::query()->where('event', 'user.unblock')->value('reason'))->toBe('Revisado');
});

test('recordAction sin motivo en una acción que no lo exige lo deja en null y con lista vacía nada lo exige', function () {
    $audit = app(AuditServiceInterface::class);

    expect($audit->recordAction('custom.action')->reason)->toBeNull();

    config(['audit.reason_required' => ['custom.action']]);
    expect(fn () => $audit->recordAction('custom.action', null, '   '))->toThrow(AuditException::class);

    config(['audit.reason_required' => []]);
    expect($audit->recordAction('custom.action')->event)->toBe('custom.action');
});

// ── Endpoints ──────────────────────────────────────────────────────────────

test('el dueño y el admin del tenant listan su auditoría; el miembro no', function () {
    auditoriaWebhook($this, $this->a);

    foreach ([$this->ownerA, $this->adminA] as $usuario) {
        $this->isoCall($this->isoHeaders($usuario, $this->a), 'GET', '/tenant/audit')->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    $this->isoCall($this->isoHeaders($this->memberA, $this->a), 'GET', '/tenant/audit')->assertForbidden();
    $this->isoCall($this->isoHeaders($this->memberA, $this->a), 'GET', '/tenant/audit/export')->assertForbidden();
    $this->asHeaders([])->getJson('/tenant/audit')->assertUnauthorized();
});

test('un rol de tenant-admin concedido en otra empresa no basta: se exige el rol del actor en ESTE tenant', function () {
    // Con equipos de Spatie apagados el rol `tenant-admin` es global: un miembro de A con ese rol Spatie
    // pero rol de tenant `member` en A no debe pasar.
    $falso = $this->isoUser($this->a, 'member');
    $falso->assignRole('tenant-admin');

    $this->isoCall($this->isoHeaders($falso, $this->a), 'GET', '/tenant/audit')->assertForbidden();
});

test('los filtros por actor, evento, modelo y fecha funcionan', function () {
    $webhook = auditoriaWebhook($this, $this->a);
    $this->ctx->run($this->a, fn () => $webhook->update(['name' => 'otro']));
    $h = $this->isoHeaders($this->ownerA, $this->a);

    $solo = fn (string $q) => collect($this->isoCall($h, 'GET', "/tenant/audit?{$q}")->assertOk()->json('data'));

    expect($solo('event=updated&model=Webhook')->pluck('event')->unique()->all())->toBe(['updated'])
        ->and($solo('model=Webhook&model_id='.$webhook->id)->count())->toBe(2)
        ->and($solo('actor_type=api_key')->count())->toBe(0)
        ->and($solo('actor_type=system&model=Webhook')->count())->toBe(2)
        ->and($solo('from='.now()->addDay()->toDateString())->count())->toBe(0)
        ->and($solo('to='.now()->addDay()->toDateString().'&model=Webhook')->count())->toBe(2);

    $this->isoCall($h, 'GET', '/tenant/audit?actor_type=robot')->assertStatus(422);
});

test('el listado es paginado en SQL', function () {
    foreach (range(1, 5) as $i) {
        auditoriaWebhook($this, $this->a, ['name' => "h{$i}"]);
    }

    $res = $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/audit?per_page=2&model=Webhook')->assertOk();

    expect($res->json('data'))->toHaveCount(2)->and($res->json('meta.total'))->toBe(5);
});

test('la exportación CSV baja en streaming con cabecera y neutraliza fórmulas', function () {
    auditoriaWebhook($this, $this->a, ['name' => '=HYPERLINK("http://malo")']);

    $res = $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/tenant/audit/export')->assertOk();
    $csv = $res->streamedContent();

    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toContain('id,created_at,tenant_id,event')
        ->and($csv)->toContain('created')
        ->and($csv)->not->toContain(',=HYPERLINK');
});

test('la auditoría de plataforma exige audit.read y filtra por tenant', function () {
    auditoriaWebhook($this, $this->a);
    auditoriaWebhook($this, $this->b);
    $h = ['Authorization' => $this->isoHeaders($this->plataforma, $this->a)['Authorization']];

    $todo = collect($this->isoCall($h, 'GET', '/admin/audit?model=Webhook')->assertOk()->json('data'))->pluck('tenant_id')->unique()->sort()->values()->all();
    $soloB = collect($this->isoCall($h, 'GET', "/admin/audit?model=Webhook&tenant_id={$this->b->id}")->assertOk()->json('data'))->pluck('tenant_id')->unique()->all();

    expect($todo)->toBe([$this->a->id, $this->b->id])->and($soloB)->toBe([$this->b->id]);

    $this->isoCall($h, 'GET', '/admin/audit/export')->assertOk();
    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/admin/audit')->assertForbidden();
    $this->isoCall($this->isoHeaders($this->ownerA, $this->a), 'GET', '/admin/audit/export')->assertForbidden();
});

// ── Retención ──────────────────────────────────────────────────────────────

test('audit:prune borra lo anterior a la retención y respeta lo reciente y --dry-run', function () {
    auditoriaWebhook($this, $this->a);
    auditoriaWebhook($this, $this->a);
    $base = AuditLog::query()->count();
    $viejo = AuditLog::query()->orderBy('id')->first();
    DB::table('audit_logs')->where('id', $viejo->id)->update(['created_at' => now()->subDays(40)]);
    config(['audit.retention_days' => 30]);

    Artisan::call('audit:prune', ['--dry-run' => true]);
    expect(AuditLog::query()->count())->toBe($base);

    Artisan::call('audit:prune');
    expect(AuditLog::query()->count())->toBe($base - 1)->and(AuditLog::query()->whereKey($viejo->id)->exists())->toBeFalse();
});

test('con retención 0 audit:prune no borra nada', function () {
    auditoriaWebhook($this, $this->a);
    $base = AuditLog::query()->count();
    DB::table('audit_logs')->update(['created_at' => now()->subYears(5)]);
    config(['audit.retention_days' => 0]);

    Artisan::call('audit:prune');

    expect(AuditLog::query()->count())->toBe($base);
});
