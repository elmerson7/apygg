<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * RoleSeeder
 *
 * Roles base del boilerplate:
 * - admin: super admin. Pasa cualquier comprobación de permiso (Gate::after en
 *   AuthServiceProvider); además se le asignan todos los permisos para que la UI
 *   de roles los muestre.
 * - manager: gestiona usuarios y roles (sin tocar permisos de roles ni al admin).
 * - user: rol por defecto de los registros (config auth.default_role). Sin permisos
 *   de administración: su perfil y sus sesiones no los piden.
 *
 * Llama a PermissionSeeder (los roles necesitan los permisos). Idempotente: crea lo
 * que falta y solo AÑADE permisos a los roles; nunca quita los que se hayan dado a mano.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $roles = [
            'admin' => [
                'display_name' => 'Administrador',
                'description' => 'Super admin: acceso total al sistema',
                'permissions' => Permission::query()->pluck('name')->all(),
            ],
            'manager' => [
                'display_name' => 'Gerente',
                'description' => 'Gestiona usuarios y roles',
                'permissions' => [
                    'users.read', 'users.create', 'users.update', 'users.manage-roles',
                    'roles.read', 'roles.create', 'roles.update',
                ],
            ],
            'user' => [
                'display_name' => 'Usuario',
                'description' => 'Usuario estándar: sin permisos de administración',
                'permissions' => [],
            ],
        ];

        foreach ($roles as $name => $definition) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['display_name' => $definition['display_name'], 'description' => $definition['description']]
            );

            if ($definition['permissions'] !== []) {
                $role->givePermissionTo($definition['permissions']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('Roles base: '.implode(', ', array_keys($roles)));
    }
}
