<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Blacklist;
use App\Models\Permission;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => prepareBackoffice());

test('redirige al login a quien llega sin sesión', function () {
    $this->get('/backoffice')->assertRedirect('/backoffice/login');
});

test('el login del back-office es una página web y no una respuesta JSON de la API', function () {
    $this->get('/backoffice/login')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

test('niega el acceso a un usuario sin el permiso backoffice.access', function () {
    $this->actingAs(backofficeUser('user'))->get('/backoffice')->assertForbidden();
});

test('niega el acceso aunque el usuario tenga permisos de usuarios si no tiene backoffice.access', function () {
    $manager = backofficeUser('manager');

    $this->actingAs($manager)->get('/backoffice/users')->assertForbidden();
});

test('exige configurar el MFA a quien tiene el permiso pero no lo ha activado', function () {
    $manager = backofficeUser('manager', mfa: false, permissions: ['backoffice.access']);

    $this->actingAs($manager)
        ->get('/backoffice')
        ->assertRedirect(route('filament.backoffice.auth.multi-factor-authentication.set-up-required'));
});

test('un administrador con MFA configurado entra al panel', function () {
    $this->actingAs(backofficeUser('admin'))->get('/backoffice')->assertOk();
});

test('un gerente con el permiso y el MFA entra al panel', function () {
    $this->actingAs(backofficeManager())->get('/backoffice')->assertOk();
});

test('resuelve el permiso del guard api para un usuario autenticado por la sesión web', function () {
    $manager = backofficeManager();

    $this->actingAs($manager, 'web');

    // El guard por defecto de la petición es web; los permisos son del guard api.
    expect(auth()->getDefaultDriver())->toBe('web')
        ->and($manager->can('backoffice.access'))->toBeTrue()
        ->and($manager->checkPermissionTo('backoffice.access'))->toBeTrue()
        ->and(backofficeUser('user')->can('backoffice.access'))->toBeFalse();
});

test('el admin entra aunque el permiso backoffice.access no esté asignado ni sembrado', function () {
    Permission::where('name', 'backoffice.access')->forceDelete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = backofficeUser('admin');

    expect($admin->canAccessPanel(Filament::getPanel('backoffice')))->toBeTrue();
});

test('niega el acceso a un usuario desactivado', function () {
    $admin = backofficeUser('admin');
    $panel = Filament::getPanel('backoffice');

    expect($admin->canAccessPanel($panel))->toBeTrue();

    $admin->delete();

    expect($admin->fresh() ?? User::withTrashed()->find($admin->id))->not->toBeNull()
        ->and(User::withTrashed()->find($admin->id)->canAccessPanel($panel))->toBeFalse();
});

test('niega el acceso a un usuario en la lista negra', function () {
    $admin = backofficeUser('admin');
    Blacklist::create(['email' => $admin->email, 'reason' => 'Fraude']);

    $this->actingAs($admin)->get('/backoffice')->assertForbidden();
});

test('niega el acceso por la lista negra de documento de identidad', function () {
    $admin = backofficeUser('admin');
    Blacklist::create(['identity_document' => $admin->identity_document ?? '12345678', 'reason' => 'Fraude']);
    $admin->forceFill(['identity_document' => $admin->identity_document ?? '12345678'])->save();

    expect($admin->fresh()->canAccessPanel(Filament::getPanel('backoffice')))->toBeFalse();
});

test('el login limita los intentos fallidos', function () {
    $user = User::factory()->create(['email' => 'intruso@example.com']);
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class).':'.request()->ip());

    $component = Livewire::test(Login::class);

    foreach (range(1, 6) as $intento) {
        $component->fillForm(['email' => $user->email, 'password' => 'incorrecta'])->call('authenticate');
    }

    $component->assertNotified();
    expect(auth('web')->check())->toBeFalse();
});

test('no deja entrar con contraseña incorrecta', function () {
    $user = User::factory()->create(['email' => 'gerente@example.com']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'incorrecta'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth('web')->check())->toBeFalse();
});

test('las respuestas del panel llevan cabeceras de seguridad y no la CSP de la API', function () {
    $response = $this->get('/backoffice/login');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

test('la API sigue respondiendo JSON en las rutas que no existen', function () {
    $this->getJson('/esta-ruta-no-existe')->assertJsonStructure(['title']);
});

test('un usuario sin users.read no puede listar usuarios en el panel', function () {
    $this->actingAs(backofficeUser('user', true, ['backoffice.access']));

    Livewire::test(ListUsers::class)->assertForbidden();
});
