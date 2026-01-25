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
        // Definir recursos y acciones del sistema
        $resources = [
            'users' => ['create', 'read', 'update', 'delete', 'restore', 'forceDelete'],
            'roles' => ['create', 'read', 'update', 'delete', 'assignPermission', 'removePermission'],
            'permissions' => ['create', 'read', 'update', 'delete'],
            'posts' => ['create', 'read', 'update', 'delete', 'publish', 'unpublish'],
            'categories' => ['create', 'read', 'update', 'delete'],
            'comments' => ['create', 'read', 'update', 'delete', 'moderate'],
        ];

        $permissions = [];

        // Generar permisos para cada recurso y acción
        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                $name = "{$resource}.{$action}";
                $displayName = $this->formatDisplayName($resource, $action);
                $description = $this->generateDescription($resource, $action);

                $permissions[] = [
                    'id' => Str::uuid()->toString(),
                    'name' => $name,
                    'display_name' => $displayName,
                    'resource' => $resource,
                    'action' => $action,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insertar permisos solo si no existen
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
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
        $userRole = Role::where('name', 'user')->first();
        $guestRole = Role::where('name', 'guest')->first();

        if (!$adminRole || !$userRole || !$guestRole) {
            $this->command->warn('Roles no encontrados. Ejecuta RoleSeeder primero.');
            return;
        }

        // Admin: Todos los permisos
        $allPermissions = Permission::all();
        $adminRole->permissions()->sync($allPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a Admin: ' . $allPermissions->count());

        // User: Permisos básicos (lectura y creación en recursos comunes)
        $userPermissions = Permission::whereIn('resource', ['posts', 'comments', 'categories'])
            ->whereIn('action', ['create', 'read', 'update', 'delete'])
            ->get();
        $userRole->permissions()->sync($userPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a User: ' . $userPermissions->count());

        // Guest: Solo lectura
        $guestPermissions = Permission::where('action', 'read')->get();
        $guestRole->permissions()->sync($guestPermissions->pluck('id')->toArray());
        $this->command->info('Permisos asignados a Guest: ' . $guestPermissions->count());
    }

    /**
     * Formatear nombre para display
     *
     * @param string $resource
     * @param string $action
     * @return string
     */
    protected function formatDisplayName(string $resource, string $action): string
    {
        $resourceDisplay = ucfirst($resource);
        $actionDisplay = match ($action) {
            'create' => 'Crear',
            'read' => 'Ver',
            'update' => 'Actualizar',
            'delete' => 'Eliminar',
            'restore' => 'Restaurar',
            'forceDelete' => 'Eliminar Permanentemente',
            'assignPermission' => 'Asignar Permisos',
            'removePermission' => 'Remover Permisos',
            'publish' => 'Publicar',
            'unpublish' => 'Despublicar',
            'moderate' => 'Moderar',
            default => ucfirst($action),
        };

        return "{$actionDisplay} {$resourceDisplay}";
    }

    /**
     * Generar descripción del permiso
     *
     * @param string $resource
     * @param string $action
     * @return string
     */
    protected function generateDescription(string $resource, string $action): string
    {
        $resourceDisplay = ucfirst($resource);
        $actionDisplay = match ($action) {
            'create' => 'crear',
            'read' => 'ver',
            'update' => 'actualizar',
            'delete' => 'eliminar',
            'restore' => 'restaurar',
            'forceDelete' => 'eliminar permanentemente',
            'assignPermission' => 'asignar permisos',
            'removePermission' => 'remover permisos',
            'publish' => 'publicar',
            'unpublish' => 'despublicar',
            'moderate' => 'moderar',
            default => $action,
        };

        return "Permite {$actionDisplay} {$resourceDisplay}";
    }
}
