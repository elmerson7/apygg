<?php

use App\Contracts\SessionServiceInterface;
use App\Enums\TenantStatus;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

// H1: con users.update (rol manager) no se toma la cuenta de alguien con más privilegios.

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function tokenDe(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function conRolDe(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function nuevaEmpresa(): Tenant
{
    $slug = 'empresa-'.Str::lower(Str::random(8));

    return Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => TenantStatus::Active]);
}

function miembroActivo(User $user, Tenant $tenant, string $role = 'member'): void
{
    DB::table('tenant_user')->insert([
        'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(), 'role' => $role,
        'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('un manager no cambia el email del dueño de una empresa', function () {
    $manager = conRolDe('manager');
    $duenio = conRolDe('tenant-owner');
    miembroActivo($duenio, nuevaEmpresa(), 'owner');
    $email = $duenio->email;

    $this->withHeaders(tokenDe($manager))
        ->putJson("/users/{$duenio->id}", ['email' => 'atacante@example.org'])
        ->assertForbidden();

    expect($duenio->fresh()->email)->toBe($email);
});

test('un manager no cambia la contraseña de quien tiene un permiso que él no tiene', function (string $permiso) {
    $manager = conRolDe('manager');
    $soporte = User::factory()->create();
    $soporte->givePermissionTo(Permission::findByName($permiso, 'api'));
    $hash = $soporte->password;

    $this->withHeaders(tokenDe($manager))
        ->putJson("/users/{$soporte->id}", ['password' => 'Otra-Clave-Segura-123!'])
        ->assertForbidden();

    expect($soporte->fresh()->password)->toBe($hash);
})->with(['tenants.access-any', 'roles.delete']);

test('un manager no edita a un miembro de una empresa a la que no pertenece', function () {
    $manager = conRolDe('manager');
    $miembro = User::factory()->create();
    miembroActivo($miembro, nuevaEmpresa());

    $this->withHeaders(tokenDe($manager))
        ->putJson("/users/{$miembro->id}", ['first_name' => 'Cambiado'])
        ->assertForbidden();
});

test('un manager sí edita a un usuario con menos privilegios y le cierra las sesiones al cambiar el email', function () {
    $manager = conRolDe('manager');
    $user = conRolDe('user');
    $version = (int) $user->token_version;
    app(SessionServiceInterface::class)->start($user, null, null);

    $this->withHeaders(tokenDe($manager))
        ->putJson("/users/{$user->id}", ['email' => 'nuevo@example.org'])
        ->assertOk();

    $user->refresh();
    expect($user->email)->toBe('nuevo@example.org')
        ->and((int) $user->token_version)->toBe($version + 1)
        ->and(UserSession::where('user_id', $user->id)->whereNull('revoked_at')->exists())->toBeFalse();
});

test('cambiar la contraseña de otro usuario cierra sus sesiones y anula sus enlaces de restablecimiento al cambiar el email', function () {
    $admin = conRolDe('admin');
    $user = conRolDe('user');
    $version = (int) $user->token_version;
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);

    $this->withHeaders(tokenDe($admin))
        ->putJson("/users/{$user->id}", ['password' => 'Otra-Clave-Segura-123!'])
        ->assertOk();

    expect((int) $user->fresh()->token_version)->toBe($version + 1);

    $emailAnterior = $user->email;
    $this->withHeaders(tokenDe($admin))
        ->putJson("/users/{$user->id}", ['email' => 'otro-nuevo@example.org'])
        ->assertOk();

    expect(DB::table('password_reset_tokens')->where('email', $emailAnterior)->exists())->toBeFalse();
});

test('un admin sigue pudiendo editar al dueño de una empresa', function () {
    $admin = conRolDe('admin');
    $duenio = conRolDe('tenant-owner');
    miembroActivo($duenio, nuevaEmpresa(), 'owner');

    $this->withHeaders(tokenDe($admin))
        ->putJson("/users/{$duenio->id}", ['first_name' => 'Editado'])
        ->assertOk();
});

test('un manager edita a un compañero de su misma empresa con menos privilegios', function () {
    $tenant = nuevaEmpresa();
    $manager = conRolDe('manager');
    miembroActivo($manager, $tenant);
    $companero = conRolDe('user');
    miembroActivo($companero, $tenant);

    $this->withHeaders(tokenDe($manager))
        ->putJson("/users/{$companero->id}", ['first_name' => 'Editado'])
        ->assertOk();
});
