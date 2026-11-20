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

        $roles = self::definitions();
        $roles['admin']['permissions'] = Permission::query()->pluck('name')->all();

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

    /**
     * Roles base y sus permisos. `admin` recibe en `run()` TODOS los permisos de la BD (aquí, el catálogo).
     * Lo usa también `Tests\TestCase` para saber si la BD de pruebas ya está sembrada.
     *
     * @return array<string, array{display_name: string, description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'admin' => [
                'display_name' => 'Administrador',
                'description' => 'Super admin: acceso total al sistema',
                'permissions' => array_column(PermissionSeeder::catalog(), 'name'),
            ],
            'manager' => [
                'display_name' => 'Gerente',
                'description' => 'Gestiona usuarios y roles',
                'permissions' => [
                    'users.read', 'users.create', 'users.update', 'users.manage-roles',
                    'roles.read', 'roles.create', 'roles.update',
                ],
            ],
            // Roles DEL TENANT (multi-tenancy.onboarding.roles): se asignan al crear/invitar miembros.
            // Con PERMISSION_TEAMS=true se asignan por tenant; ver docs/tenant-onboarding.md.
            'tenant-owner' => [
                'display_name' => 'Dueño de la empresa',
                'description' => 'Gestiona miembros, invitaciones y ajustes de su empresa',
                'permissions' => ['tenant-members.read', 'tenant-members.manage', 'tenant-members.export', 'tenant-invitations.manage', 'tenant-settings.read', 'tenant-settings.manage', 'tenant-audit.read'],
            ],
            'tenant-admin' => [
                'display_name' => 'Administrador de la empresa',
                'description' => 'Gestiona miembros, invitaciones y ajustes de su empresa (sin superar su rango)',
                'permissions' => ['tenant-members.read', 'tenant-members.manage', 'tenant-members.export', 'tenant-invitations.manage', 'tenant-settings.read', 'tenant-settings.manage', 'tenant-audit.read'],
            ],
            'tenant-member' => [
                'display_name' => 'Miembro de la empresa',
                'description' => 'Ve a los demás miembros y los ajustes de su empresa',
                'permissions' => ['tenant-members.read', 'tenant-settings.read'],
            ],
            'user' => [
                'display_name' => 'Usuario',
                'description' => 'Usuario estándar: sin permisos de administración',
                'permissions' => [],
            ],
        ];
    }
}
