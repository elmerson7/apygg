<?php

use App\Contracts\ConsentServiceInterface;
use App\Exceptions\PrivacyException;
use App\Models\LegalDocument;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;

uses(RefreshDatabase::class, ActsWithHeaders::class);

function cH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

function cDoc(string $key, string $version, string $locale = 'es', ?string $effective = null): LegalDocument
{
    return LegalDocument::query()->create([
        'key' => $key, 'version' => $version, 'locale' => $locale, 'title' => "{$key} {$version} {$locale}", 'body' => 'texto',
        'content_hash' => hash('sha256', 'x'), 'effective_at' => $effective ?? now()->subDay(),
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    config(['privacy.consents.required' => ['terms', 'privacy']]);
    $this->user = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

test('lee la versión vigente de forma pública, en el idioma pedido y con reserva', function () {
    cDoc('terms', '1', 'es');
    cDoc('terms', '1', 'en');
    cDoc('terms', '2', 'es', now()->addDay()->toDateTimeString()); // futura: aún no vigente

    $this->getJson('/legal')->assertOk()->assertJsonPath('data.0.version', '1');
    $this->getJson('/legal/terms?locale=en')->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.body', 'texto');
    $this->getJson('/legal/terms?locale=fr')->assertOk()->assertJsonPath('data.version', '1');
    $this->getJson('/legal/inexistente')->assertNotFound()->assertJsonPath('code', 'legal_document_not_found');
});

test('el admin publica una versión y una traducción hereda la fecha de vigencia', function () {
    $body = ['key' => 'terms', 'version' => '3', 'locale' => 'es', 'title' => 'T', 'body' => 'b', 'effective_at' => '2030-01-01 00:00:00'];

    $this->asHeaders(cH($this->admin))->postJson('/admin/legal-documents', $body)->assertCreated();
    $this->asHeaders(cH($this->admin))->postJson('/admin/legal-documents', ['locale' => 'en', 'effective_at' => '2020-01-01'] + $body)->assertCreated();

    expect(LegalDocument::query()->pluck('effective_at')->map->toDateString()->unique()->all())->toBe(['2030-01-01']);
    $this->asHeaders(cH($this->admin))->postJson('/admin/legal-documents', $body)->assertStatus(422); // misma versión e idioma: inmutable
});

test('publicar sin permiso responde 403 y sin token 401', function () {
    $body = ['key' => 'terms', 'version' => '1', 'locale' => 'es', 'title' => 'T', 'body' => 'b'];

    $this->asHeaders(cH($this->user))->postJson('/admin/legal-documents', $body)->assertForbidden();
    $this->asHeaders(cH($this->user))->getJson('/admin/legal-documents')->assertForbidden();
    $this->asHeaders([])->postJson('/admin/legal-documents', $body)->assertUnauthorized();
    expect(LegalDocument::query()->count())->toBe(0);
});

test('los documentos legales no se pueden modificar ni borrar', function () {
    $doc = cDoc('terms', '1');

    expect(fn () => $doc->update(['body' => 'otro']))->toThrow(PrivacyException::class)
        ->and(fn () => $doc->delete())->toThrow(PrivacyException::class);
});

test('el usuario acepta la versión vigente y queda registro con versión, IP y navegador', function () {
    cDoc('terms', '1');

    $this->asHeaders(cH($this->user))->withHeader('User-Agent', 'PruebaUA')
        ->postJson('/user/consents', ['documents' => [['key' => 'terms', 'version' => '1']]])
        ->assertOk()->assertJsonPath('data.consents.0.status', 'accepted');

    $row = UserConsent::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($row->version)->toBe('1')->and($row->action)->toBe('accepted')->and($row->ip_address)->not->toBeNull()->and($row->user_agent)->toBe('PruebaUA');
});

test('aceptar dos veces la misma versión no duplica el registro', function () {
    cDoc('terms', '1');
    $svc = app(ConsentServiceInterface::class);

    $svc->accept($this->user, [['key' => 'terms', 'version' => '1']], '1.1.1.1', 'ua');
    $svc->accept($this->user, [['key' => 'terms', 'version' => '1']], '1.1.1.1', 'ua');

    expect(UserConsent::query()->count())->toBe(1);
});

test('aceptar una versión que no es la vigente responde 409 y no registra nada', function () {
    cDoc('terms', '1', 'es', now()->subDays(2)->toDateTimeString());
    cDoc('terms', '2', 'es', now()->subDay()->toDateTimeString());

    $this->asHeaders(cH($this->user))->postJson('/user/consents', ['documents' => [['key' => 'terms', 'version' => '1']]])
        ->assertStatus(409)->assertJsonPath('code', 'consent_version_outdated');
    expect(UserConsent::query()->count())->toBe(0);
});

test('una versión nueva obliga a volver a aceptar', function () {
    cDoc('terms', '1', 'es', now()->subDays(2)->toDateTimeString());
    $svc = app(ConsentServiceInterface::class);
    $svc->accept($this->user, [['key' => 'terms', 'version' => '1']], null, null);
    expect($svc->pendingFor($this->user))->toBe([]);

    cDoc('terms', '2', 'es', now()->subDay()->toDateTimeString());

    expect(array_column($svc->pendingFor($this->user), 'version'))->toBe(['2']);
    $this->asHeaders(cH($this->user))->getJson('/user/consents')->assertJsonPath('data.consents.0.status', 'outdated');
});

test('retirar un consentimiento opcional lo registra y uno obligatorio no se puede retirar', function () {
    cDoc('marketing', '1');
    cDoc('terms', '1');
    $svc = app(ConsentServiceInterface::class);
    $svc->accept($this->user, [['key' => 'marketing', 'version' => '1'], ['key' => 'terms', 'version' => '1']], null, null);

    $this->asHeaders(cH($this->user))->deleteJson('/user/consents/marketing')->assertOk();
    $this->asHeaders(cH($this->user))->deleteJson('/user/consents/marketing')->assertOk(); // idempotente
    $this->asHeaders(cH($this->user))->deleteJson('/user/consents/terms')->assertStatus(422)->assertJsonPath('code', 'consent_mandatory');

    expect(UserConsent::query()->where('document_key', 'marketing')->pluck('action')->all())->toBe(['accepted', 'withdrawn']);
});

test('con la exigencia activa, un obligatorio pendiente da 409 con la lista, salvo en rutas exentas', function () {
    config(['privacy.consents.enforce' => true]);
    cDoc('terms', '1');
    cDoc('privacy', '1');

    $this->asHeaders(cH($this->user))->getJson('/user/profile')
        ->assertStatus(409)->assertJsonPath('code', 'consent_required')->assertJsonCount(2, 'pending');
    $this->asHeaders(cH($this->user))->getJson('/user/consents')->assertOk();
    $this->asHeaders([])->getJson('/legal')->assertOk();

    $this->asHeaders(cH($this->user))->postJson('/user/consents', ['documents' => [['key' => 'terms', 'version' => '1'], ['key' => 'privacy', 'version' => '1']]])->assertOk();
    $this->asHeaders(cH($this->user))->getJson('/user/profile')->assertOk();
});

test('apagada la exigencia no bloquea a nadie', function () {
    cDoc('terms', '1');

    $this->asHeaders(cH($this->user))->getJson('/user/profile')->assertOk();
});

test('un usuario no ve ni toca los consentimientos de otro', function () {
    cDoc('terms', '1');
    $otro = User::factory()->create();
    app(ConsentServiceInterface::class)->accept($otro, [['key' => 'terms', 'version' => '1']], null, null);

    $this->asHeaders(cH($this->user))->getJson('/user/consents')->assertJsonPath('data.consents.0.status', 'pending');
    expect(Role::query()->count())->toBeGreaterThan(0);
});
