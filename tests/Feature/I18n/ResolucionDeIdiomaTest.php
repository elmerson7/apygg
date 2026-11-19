<?php

use App\Contracts\LocaleResolverInterface;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    config(['app.locale' => 'es', 'i18n.default_locale' => 'es', 'i18n.locales' => ['es', 'en']]);
    App::setLocale('es');
});

function i18nJwt(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

/** Cabecera de un idioma no soportado: en tests, Request::create añade `en-us` por defecto, así que «sin cabecera» se simula así. */
const SIN_IDIOMA = ['Accept-Language' => 'fr'];

function i18nUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $user->assignRole(Role::where('name', 'user')->first());

    return $user;
}

test('sin cabecera ni preferencias responde en el idioma por defecto con Content-Language', function () {
    $this->getJson('/user/locale', SIN_IDIOMA)->assertUnauthorized()
        ->assertHeader('Content-Language', 'es')
        ->assertJsonPath('message', 'No autenticado');
});

test('la cabecera Accept-Language soportada cambia el idioma de los errores', function () {
    $this->getJson('/user/locale', ['Accept-Language' => 'en'])->assertUnauthorized()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('message', 'Unauthenticated')
        ->assertJsonPath('code', 'unauthenticated');
});

test('una cabecera con variante regional y calidades usa el primer idioma soportado', function () {
    $this->getJson('/user/locale', ['Accept-Language' => 'fr-FR,en-US;q=0.8,es;q=0.5'])
        ->assertHeader('Content-Language', 'en');
});

test('una cabecera con un idioma no soportado cae al idioma por defecto', function () {
    $this->getJson('/user/locale', ['Accept-Language' => 'fr-FR,de;q=0.8'])
        ->assertHeader('Content-Language', 'es')
        ->assertJsonPath('message', 'No autenticado');
});

test('un 404 sin ruta también sale en el idioma de la cabecera', function () {
    $this->getJson('/no-existe-esta-ruta', ['Accept-Language' => 'en'])
        ->assertNotFound()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('title', 'Endpoint not found.');
});

test('los errores de validación salen en el idioma resuelto', function () {
    $user = i18nUser();

    $this->putJson('/user/locale', ['timezone' => 'Marte/Olympus'], i18nJwt($user) + ['Accept-Language' => 'en'])
        ->assertStatus(422)
        ->assertJsonPath('errors.timezone.0', 'The time zone field must be a valid timezone.');

    $this->putJson('/user/locale', ['timezone' => 'Marte/Olympus'], i18nJwt($user) + SIN_IDIOMA)
        ->assertStatus(422)
        ->assertJsonPath('errors.timezone.0', 'El campo zona horaria debe ser una zona horaria válida.');
});

test('la preferencia del usuario gana a la cabecera', function () {
    $user = i18nUser(['locale' => 'en']);

    $this->getJson('/user/locale', i18nJwt($user) + ['Accept-Language' => 'es'])
        ->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('data.effective.locale', 'en');
});

test('sin preferencia de usuario gana el tenant y luego la cabecera', function () {
    $this->enableTenancy();
    $tenant = $this->makeTenant('acme');
    $tenant->update(['locale' => 'en']);
    $user = i18nUser();
    $this->addMember($tenant, $user);
    $headers = i18nJwt($user) + ['X-Tenant' => 'acme'];

    // Tenant en inglés vence a la cabecera en español
    $this->getJson('/tenant/locale', $headers + ['Accept-Language' => 'es'])
        ->assertOk()->assertHeader('Content-Language', 'en');

    // Sin idioma de tenant, manda la cabecera
    $tenant->update(['locale' => null]);
    $this->getJson('/tenant/locale', $headers + ['Accept-Language' => 'en'])
        ->assertOk()->assertHeader('Content-Language', 'en');

    // Usuario > tenant
    $tenant->update(['locale' => 'en']);
    $user->update(['locale' => 'es']);
    app('auth')->forgetGuards(); // el test comparte proceso: cada petición real arranca con guards limpios
    $this->getJson('/tenant/locale', $headers + SIN_IDIOMA)
        ->assertOk()->assertHeader('Content-Language', 'es')
        ->assertJsonPath('data.effective.locale', 'es');
});

test('el idioma no se filtra entre dos peticiones seguidas en el mismo proceso', function () {
    $en = $this->getJson('/user/locale', ['Accept-Language' => 'en']);
    $afterEn = App::getLocale();
    $es = $this->getJson('/user/locale', SIN_IDIOMA);
    $enAgain = $this->getJson('/user/locale', ['Accept-Language' => 'en']);

    expect($en->json('message'))->toBe('Unauthenticated')
        ->and($afterEn)->toBe('es')
        ->and($es->json('message'))->toBe('No autenticado')
        ->and($es->headers->get('Content-Language'))->toBe('es')
        ->and($enAgain->json('message'))->toBe('Unauthenticated')
        ->and(App::getLocale())->toBe('es');
});

test('el idioma tampoco se filtra tras una petición que termina en excepción', function () {
    $this->getJson('/no-existe-esta-ruta', ['Accept-Language' => 'en'])->assertNotFound();

    expect(App::getLocale())->toBe('es');
    $this->getJson('/no-existe-esta-ruta', SIN_IDIOMA)->assertJsonPath('title', 'Endpoint no encontrado.');
});

test('el resolutor da idioma, zona horaria y moneda efectivos con herencia', function () {
    config(['i18n.default_timezone' => 'UTC', 'i18n.default_currency' => 'USD']);
    $this->enableTenancy();
    $tenant = $this->makeTenant('acme');
    $tenant->update(['timezone' => 'America/Lima', 'currency' => 'PEN', 'locale' => 'en']);
    $user = i18nUser();
    $resolver = app(LocaleResolverInterface::class);

    expect($resolver->timezone())->toBe('UTC')->and($resolver->currency())->toBe('USD');

    app(TenantContext::class)->run($tenant, function () use ($resolver, $user) {
        expect($resolver->timezone())->toBe('America/Lima')
            ->and($resolver->currency())->toBe('PEN')
            ->and($resolver->localeFor($user))->toBe('en');

        $user->update(['timezone' => 'Europe/Madrid']);
        expect($resolver->timezoneFor($user->refresh()))->toBe('Europe/Madrid');
    });
});

test('un idioma o zona horaria guardados que ya no son válidos se ignoran', function () {
    $user = i18nUser();
    $user->forceFill(['locale' => 'xx', 'timezone' => 'Nada/Nunca'])->save();
    $resolver = app(LocaleResolverInterface::class);

    expect($resolver->localeFor($user))->toBe('es')->and($resolver->timezoneFor($user))->toBe(config('i18n.default_timezone'));
});

test('un locale que no está en config no se acepta aunque exista lang', function () {
    config(['i18n.locales' => ['es']]);

    $this->getJson('/user/locale', ['Accept-Language' => 'en'])->assertHeader('Content-Language', 'es');
});

test('el usuario actualiza su idioma y zona horaria y puede volver a heredar con null', function () {
    $user = i18nUser();

    $this->putJson('/user/locale', ['locale' => 'en', 'timezone' => 'America/Lima'], i18nJwt($user))
        ->assertOk()
        ->assertJsonPath('data.stored.locale', 'en')
        ->assertJsonPath('data.effective.timezone', 'America/Lima');

    $this->putJson('/user/locale', ['locale' => null, 'timezone' => null], i18nJwt($user) + SIN_IDIOMA)
        ->assertOk()
        ->assertJsonPath('data.stored.locale', null)
        ->assertJsonPath('data.effective.timezone', config('i18n.default_timezone'));
});

test('el usuario no puede fijar un idioma no soportado', function () {
    $this->putJson('/user/locale', ['locale' => 'fr'], i18nJwt(i18nUser()))
        ->assertStatus(422)->assertJsonValidationErrors('locale');
});

test('el perfil también acepta y devuelve el idioma', function () {
    $user = i18nUser();

    $this->putJson('/user/profile', ['locale' => 'en'], i18nJwt($user))
        ->assertOk()->assertJsonPath('data.locale', 'en');
    $this->putJson('/user/profile', ['locale' => 'zz'], i18nJwt($user))->assertStatus(422);
});

test('sin sesión no se pueden ver ni cambiar las preferencias', function () {
    $this->putJson('/user/locale', ['locale' => 'en'])->assertUnauthorized();
});
