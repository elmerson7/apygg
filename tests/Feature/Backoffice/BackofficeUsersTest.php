<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => prepareBackoffice());

test('un administrador lista los usuarios en el panel', function () {
    $admin = backofficeUser('admin');
    $usuarios = User::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($usuarios);
});

test('el listado de usuarios oculta a los desactivados salvo que se pida', function () {
    $admin = backofficeUser('admin');
    $activo = User::factory()->create();
    $inactivo = User::factory()->create();
    $inactivo->delete();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$activo])
        ->assertCanNotSeeTableRecords([$inactivo])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$activo, $inactivo]);
});

test('un gerente con acceso al panel puede listar usuarios', function () {
    $this->actingAs(backofficeManager());

    Livewire::test(ListUsers::class)->assertOk();
});

test('un administrador crea un usuario con rol desde el panel usando UserService', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Laura',
            'last_name' => 'Gómez',
            'username' => 'lauragomez',
            'email' => 'Laura@Example.com',
            'identity_document' => '87654321',
            'password' => 'Password123!',
            'role_ids' => [roleId('manager')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $creado = User::where('username', 'lauragomez')->firstOrFail();

    expect($creado->email)->toBe('laura@example.com') // UserService normaliza el correo
        ->and($creado->hasRole('manager'))->toBeTrue()
        ->and($creado->profile)->not->toBeNull()
        ->and($creado->profile->first_name)->toBe('Laura');
});

test('el formulario de alta rechaza una contraseña débil', function () {
    $this->actingAs(backofficeUser('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Laura',
            'last_name' => 'Gómez',
            'username' => 'lauragomez',
            'email' => 'laura@example.com',
            'identity_document' => '87654321',
            'password' => '123',
            'role_ids' => [roleId('user')],
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('username', 'lauragomez')->exists())->toBeFalse();
});

test('un gerente no puede dar el rol admin al crear un usuario desde el panel', function () {
    $this->actingAs(backofficeManager(['users.create']));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Laura',
            'last_name' => 'Gómez',
            'username' => 'lauragomez',
            'email' => 'laura@example.com',
            'identity_document' => '87654321',
            'password' => 'Password123!',
            'role_ids' => [roleId('admin')],
        ])
        ->call('create')
        ->assertHasErrors();

    expect(User::where('username', 'lauragomez')->exists())->toBeFalse();
});

test('un gerente no puede dar el rol admin desde la acción de roles del panel', function () {
    $manager = backofficeManager();
    $objetivo = User::factory()->create();
    $objetivo->assignRole('user');

    $this->actingAs($manager);

    Livewire::test(ViewUser::class, ['record' => $objetivo->getKey()])
        ->callAction('assignRoles', ['role_ids' => [roleId('admin')]])
        ->assertHasActionErrors();

    expect($objetivo->fresh()->hasRole('admin'))->toBeFalse();
});

test('un gerente puede asignar un rol cuyos permisos ya tiene', function () {
    $manager = backofficeManager();
    $objetivo = User::factory()->create();
    $objetivo->assignRole('user');

    $this->actingAs($manager);

    Livewire::test(ViewUser::class, ['record' => $objetivo->getKey()])
        ->callAction('assignRoles', ['role_ids' => [roleId('manager')]])
        ->assertHasNoActionErrors();

    expect($objetivo->fresh()->hasRole('manager'))->toBeTrue();
});

test('un gerente no puede editar a un administrador', function () {
    $admin = backofficeUser('admin');

    $this->actingAs(backofficeManager());

    Livewire::test(EditUser::class, ['record' => $admin->getKey()])->assertForbidden();
});

test('un gerente no puede cambiar los roles de un administrador', function () {
    $admin = backofficeUser('admin');

    $this->actingAs(backofficeManager());

    Livewire::test(ViewUser::class, ['record' => $admin->getKey()])
        ->assertActionHidden('assignRoles')
        ->assertActionHidden('deactivate');
});

test('el panel no deja sin administradores al sistema al quitarse el rol el único admin', function () {
    $admin = backofficeUser('admin');

    $this->actingAs($admin);

    Livewire::test(ViewUser::class, ['record' => $admin->getKey()])
        ->callAction('assignRoles', ['role_ids' => [roleId('user')]])
        ->assertNotified('No se puede dejar el sistema sin administradores');

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('un administrador desactiva y vuelve a activar a un usuario', function () {
    $admin = backofficeUser('admin');
    $objetivo = User::factory()->create();
    $objetivo->assignRole('user');

    $this->actingAs($admin);

    Livewire::test(ViewUser::class, ['record' => $objetivo->getKey()])
        ->callAction('deactivate');

    expect(User::withTrashed()->find($objetivo->id)->trashed())->toBeTrue();

    Livewire::test(ViewUser::class, ['record' => $objetivo->getKey()])
        ->callAction('activate');

    expect(User::find($objetivo->id))->not->toBeNull();
});

test('un administrador no puede desactivarse a sí mismo desde el panel', function () {
    $admin = backofficeUser('admin');

    $this->actingAs($admin);

    Livewire::test(ViewUser::class, ['record' => $admin->getKey()])->assertActionHidden('deactivate');
});

test('un administrador edita el perfil de un usuario desde el panel', function () {
    $admin = backofficeUser('admin');
    $objetivo = User::factory()->create();
    $objetivo->assignRole('user');

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $objetivo->getKey()])
        ->fillForm(['first_name' => 'Renombrado', 'last_name' => 'Apellido', 'username' => 'renombrado'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($objetivo->fresh()->username)->toBe('renombrado')
        ->and($objetivo->fresh()->name)->toBe('Renombrado Apellido');
});

test('editar la propia cuenta desde el panel no cambia el correo ni la contraseña', function () {
    $admin = backofficeUser('admin');
    $correo = $admin->email;
    $hash = $admin->password;

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['first_name' => 'Yo', 'last_name' => 'Mismo', 'email' => 'otro@example.com', 'password' => 'Password123!'])
        ->call('save');

    expect($admin->fresh()->email)->toBe($correo)
        ->and($admin->fresh()->password)->toBe($hash);
});

test('la policy de roles impide a un gerente asignar el rol admin aunque se salte el formulario', function () {
    $manager = backofficeManager();

    expect(Gate::forUser($manager)->allows('assign', Role::where('name', 'admin')->first()))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('assign', Role::where('name', 'manager')->first()))->toBeTrue();
});
