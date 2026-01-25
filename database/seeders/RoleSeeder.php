<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RoleSeeder
 *
 * Seeder para crear roles base del sistema RBAC:
 * - Admin: Acceso total al sistema
 * - User: Usuario estándar con acceso básico
 * - Guest: Usuario invitado con acceso de solo lectura
 *
 * @package Database\Seeders
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
                'id' => $this->generateUuid(),
                'name' => 'admin',
                'display_name' => 'Administrador',
                'description' => 'Rol de administrador con acceso total al sistema. Puede gestionar usuarios, roles, permisos y todas las funcionalidades.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->generateUuid(),
                'name' => 'user',
                'display_name' => 'Usuario',
                'description' => 'Rol de usuario estándar con acceso básico a las funcionalidades del sistema.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->generateUuid(),
                'name' => 'guest',
                'display_name' => 'Invitado',
                'description' => 'Rol de invitado con acceso de solo lectura limitado.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insertar roles solo si no existen
        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                $role
            );
        }

        $this->command->info('Roles base creados: Admin, User, Guest');
    }

    /**
     * Generar UUID v4
     *
     * @return string
     */
    protected function generateUuid(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}
