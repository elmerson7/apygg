<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PermissionSeeder
 *
 * Seeder para crear permisos base del sistema RBAC.
 * Estructura de permisos: recurso.accion (ej: users.create, users.read, etc.)
 *
 * @package Database\Seeders
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Definir permisos completos basados en initial_data.sql
        $permissions = [
            // Permisos de Usuarios
            ['name' => 'users.create', 'resource' => 'users', 'action' => 'create', 'display_name' => 'Crear Usuarios', 'description' => 'Permite crear nuevos usuarios'],
            ['name' => 'users.read', 'resource' => 'users', 'action' => 'read', 'display_name' => 'Ver Usuarios', 'description' => 'Permite ver listado y detalles de usuarios'],
            ['name' => 'users.update', 'resource' => 'users', 'action' => 'update', 'display_name' => 'Actualizar Usuarios', 'description' => 'Permite actualizar información de usuarios'],
            ['name' => 'users.delete', 'resource' => 'users', 'action' => 'delete', 'display_name' => 'Eliminar Usuarios', 'description' => 'Permite eliminar usuarios'],
            ['name' => 'users.manage-roles', 'resource' => 'users', 'action' => 'manage-roles', 'display_name' => 'Gestionar Roles de Usuarios', 'description' => 'Permite asignar y quitar roles a usuarios'],

            // Permisos de Roles
            ['name' => 'roles.create', 'resource' => 'roles', 'action' => 'create', 'display_name' => 'Crear Roles', 'description' => 'Permite crear nuevos roles'],
            ['name' => 'roles.read', 'resource' => 'roles', 'action' => 'read', 'display_name' => 'Ver Roles', 'description' => 'Permite ver listado y detalles de roles'],
            ['name' => 'roles.update', 'resource' => 'roles', 'action' => 'update', 'display_name' => 'Actualizar Roles', 'description' => 'Permite actualizar información de roles'],
            ['name' => 'roles.delete', 'resource' => 'roles', 'action' => 'delete', 'display_name' => 'Eliminar Roles', 'description' => 'Permite eliminar roles'],
            ['name' => 'roles.manage-permissions', 'resource' => 'roles', 'action' => 'manage-permissions', 'display_name' => 'Gestionar Permisos de Roles', 'description' => 'Permite asignar y quitar permisos a roles'],

            // Permisos de Permisos
            ['name' => 'permissions.create', 'resource' => 'permissions', 'action' => 'create', 'display_name' => 'Crear Permisos', 'description' => 'Permite crear nuevos permisos'],
            ['name' => 'permissions.read', 'resource' => 'permissions', 'action' => 'read', 'display_name' => 'Ver Permisos', 'description' => 'Permite ver listado y detalles de permisos'],
            ['name' => 'permissions.update', 'resource' => 'permissions', 'action' => 'update', 'display_name' => 'Actualizar Permisos', 'description' => 'Permite actualizar información de permisos'],
            ['name' => 'permissions.delete', 'resource' => 'permissions', 'action' => 'delete', 'display_name' => 'Eliminar Permisos', 'description' => 'Permite eliminar permisos'],

            // Permisos de Posts/Contenido
            ['name' => 'posts.create', 'resource' => 'posts', 'action' => 'create', 'display_name' => 'Crear Posts', 'description' => 'Permite crear nuevos posts'],
            ['name' => 'posts.read', 'resource' => 'posts', 'action' => 'read', 'display_name' => 'Ver Posts', 'description' => 'Permite ver listado y detalles de posts'],
            ['name' => 'posts.update', 'resource' => 'posts', 'action' => 'update', 'display_name' => 'Actualizar Posts', 'description' => 'Permite actualizar posts propios'],
            ['name' => 'posts.update-any', 'resource' => 'posts', 'action' => 'update-any', 'display_name' => 'Actualizar Cualquier Post', 'description' => 'Permite actualizar cualquier post'],
            ['name' => 'posts.delete', 'resource' => 'posts', 'action' => 'delete', 'display_name' => 'Eliminar Posts', 'description' => 'Permite eliminar posts propios'],
            ['name' => 'posts.delete-any', 'resource' => 'posts', 'action' => 'delete-any', 'display_name' => 'Eliminar Cualquier Post', 'description' => 'Permite eliminar cualquier post'],
            ['name' => 'posts.moderate', 'resource' => 'posts', 'action' => 'moderate', 'display_name' => 'Moderar Posts', 'description' => 'Permite moderar y aprobar posts'],

            // Permisos de Comentarios
            ['name' => 'comments.create', 'resource' => 'comments', 'action' => 'create', 'display_name' => 'Crear Comentarios', 'description' => 'Permite crear comentarios'],
            ['name' => 'comments.read', 'resource' => 'comments', 'action' => 'read', 'display_name' => 'Ver Comentarios', 'description' => 'Permite ver comentarios'],
            ['name' => 'comments.update', 'resource' => 'comments', 'action' => 'update', 'display_name' => 'Actualizar Comentarios', 'description' => 'Permite actualizar comentarios propios'],
            ['name' => 'comments.delete', 'resource' => 'comments', 'action' => 'delete', 'display_name' => 'Eliminar Comentarios', 'description' => 'Permite eliminar comentarios propios'],
            ['name' => 'comments.moderate', 'resource' => 'comments', 'action' => 'moderate', 'display_name' => 'Moderar Comentarios', 'description' => 'Permite moderar comentarios'],

            // Permisos de Sistema
            ['name' => 'system.settings', 'resource' => 'system', 'action' => 'settings', 'display_name' => 'Gestionar Configuración', 'description' => 'Permite modificar configuración del sistema'],
            ['name' => 'system.logs', 'resource' => 'system', 'action' => 'logs', 'display_name' => 'Ver Logs', 'description' => 'Permite ver logs del sistema'],
            ['name' => 'system.backup', 'resource' => 'system', 'action' => 'backup', 'display_name' => 'Gestionar Backups', 'description' => 'Permite crear y restaurar backups'],
            ['name' => 'system.users', 'resource' => 'system', 'action' => 'users', 'display_name' => 'Gestionar Usuarios del Sistema', 'description' => 'Permite gestionar usuarios del sistema'],
        ];

        // Insertar permisos solo si no existen
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('Permisos base creados: ' . count($permissions) . ' permisos');

        // Asignar permisos a roles según jerarquía
        $this->assignPermissionsToRoles();

        $this->command->info('Permisos asignados a roles según jerarquía');
    }

    /**
     * Asignar permisos a roles según jerarquía
     *
     * @return void
     */
    protected function assignPermissionsToRoles(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $managerRole = Role::where('name', 'manager')->first();
        $userRole = Role::where('name', 'user')->first();
        $guestRole = Role::where('name', 'guest')->first();
        $moderatorRole = Role::where('name', 'moderator')->first();
        $editorRole = Role::where('name', 'editor')->first();

        if (!$adminRole || !$userRole || !$guestRole) {
            $this->command->warn('Roles no encontrados. Ejecuta RoleSeeder primero.');
            return;
        }

        // Admin: Todos los permisos
        $allPermissions = Permission::all();
        $adminRole->permissions()->sync($allPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a Admin: ' . $allPermissions->count());

        // Manager: Permisos de gestión (sin sistema)
        if ($managerRole) {
            $managerPermissions = Permission::whereIn('name', [
                'users.create', 'users.read', 'users.update', 'users.manage-roles',
                'roles.create', 'roles.read', 'roles.update',
                'posts.create', 'posts.read', 'posts.update-any', 'posts.delete-any',
                'comments.create', 'comments.read', 'comments.moderate',
            ])->get();
            $managerRole->permissions()->sync($managerPermissions->pluck('id')->toArray());
            $this->command->info('Permisos asignados a Manager: ' . $managerPermissions->count());
        }

        // User: Permisos básicos
        $userPermissions = Permission::whereIn('name', [
            'users.read', 'roles.read', 'permissions.read',
            'posts.create', 'posts.read', 'posts.update', 'posts.delete',
            'comments.create', 'comments.read', 'comments.update', 'comments.delete',
        ])->get();
        $userRole->permissions()->sync($userPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a User: ' . $userPermissions->count());

        // Guest: Solo lectura
        $guestPermissions = Permission::where('action', 'read')->get();
        $guestRole->permissions()->sync($guestPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a Guest: ' . $guestPermissions->count());

        // Moderator: Permisos de moderación
        if ($moderatorRole) {
            $moderatorPermissions = Permission::whereIn('name', [
                'users.read',
                'posts.create', 'posts.read', 'posts.update-any', 'posts.delete-any', 'posts.moderate',
                'comments.create', 'comments.read', 'comments.delete', 'comments.moderate',
            ])->get();
            $moderatorRole->permissions()->sync($moderatorPermissions->pluck('id')->toArray());
            $this->command->info('Permisos asignados a Moderator: ' . $moderatorPermissions->count());
        }

        // Editor: Permisos de edición
        if ($editorRole) {
            $editorPermissions = Permission::whereIn('name', [
                'users.read',
                'posts.create', 'posts.read', 'posts.update', 'posts.delete',
                'comments.create', 'comments.read', 'comments.update', 'comments.delete',
            ])->get();
            $editorRole->permissions()->sync($editorPermissions->pluck('id')->toArray());
            $this->command->info('Permisos asignados a Editor: ' . $editorPermissions->count());
        }
    }

}
