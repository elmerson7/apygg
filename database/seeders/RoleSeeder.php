<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder
 *
 * Seeder para crear roles base del sistema RBAC:
 * - Admin: Acceso total al sistema
 * - Manager: Gestión y supervisión
 * - User: Usuario estándar con acceso básico
 * - Guest: Usuario invitado con acceso de solo lectura
 * - Moderator: Moderación de contenido
 * - Editor: Edición de contenido
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrador',
                'description' => 'Rol con todos los permisos del sistema',
            ],
            [
                'name' => 'manager',
                'display_name' => 'Gerente',
                'description' => 'Rol con permisos de gestión y supervisión',
            ],
            [
                'name' => 'user',
                'display_name' => 'Usuario',
                'description' => 'Rol básico de usuario con permisos limitados',
            ],
            [
                'name' => 'guest',
                'display_name' => 'Invitado',
                'description' => 'Rol de solo lectura sin permisos de escritura',
            ],
            [
                'name' => 'moderator',
                'display_name' => 'Moderador',
                'description' => 'Rol con permisos de moderación de contenido',
            ],
            [
                'name' => 'editor',
                'display_name' => 'Editor',
                'description' => 'Rol con permisos de edición de contenido',
            ],
        ];

        // Insertar roles solo si no existen
        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                array_merge($role, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('Roles base creados: Admin, Manager, User, Guest, Moderator, Editor');
    }
}
