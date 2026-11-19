<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;

/** Secreto TOTP de pruebas: basta con que el usuario "tenga" MFA configurado. */
const BACKOFFICE_TEST_SECRET = 'JBSWY3DPEHPK3PXP';

/** Siembra roles y permisos y deja el panel actual para los tests de Livewire. */
function prepareBackoffice(): void
{
    // Los helpers de formularios de Filament (fillForm...) solo actúan con `runningUnitTests()`,
    // que mira el binding `env`: si el entorno trae APP_ENV distinto (p. ej. `dev` en Docker),
    // se fuerza aquí para que no dependan de cómo se lance Pest.
    app()->detectEnvironment(fn (): string => 'testing');

    test()->seed(RoleSeeder::class);

    Filament::setCurrentPanel('backoffice');
}

/**
 * Usuario con el rol dado (guard `api`, como en producción), con MFA configurado salvo que se pida lo contrario.
 *
 * @param  array<int, string>  $permissions  Permisos directos extra
 */
function backofficeUser(string $role = 'admin', bool $mfa = true, array $permissions = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    if ($mfa) {
        $user->saveAppAuthenticationSecret(BACKOFFICE_TEST_SECRET);
    }

    return $user->fresh();
}

/** Un gerente (users.* y roles.* básicos) al que además se le deja entrar al panel. */
function backofficeManager(array $extra = []): User
{
    return backofficeUser('manager', true, ['backoffice.access', ...$extra]);
}

function roleId(string $name): int
{
    return (int) Role::where('name', $name)->value('id');
}
