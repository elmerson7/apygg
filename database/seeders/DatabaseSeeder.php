<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DatabaseSeeder
 *
 * Seeder principal que ejecuta todos los seeders en el orden correcto.
 * Orden de ejecución:
 * 1. RoleSeeder - Crea roles base
 * 2. PermissionSeeder - Crea permisos y los asigna a roles
 * 3. UserSeeder - Crea usuarios de prueba con roles asignados
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        DB::unprepared(file_get_contents(database_path('seeders/initial_data.sql')));
        // Seeders de RBAC (deben ejecutarse primero)
        // $this->call([
        //     RoleSeeder::class,      // 1. Crear roles
        //     PermissionSeeder::class, // 2. Crear permisos y asignarlos a roles
        //     UserSeeder::class,       // 3. Crear usuarios de prueba con roles
        // ]);

        $this->command->info('✅ Seeders ejecutados correctamente');
        $this->command->info('📝 Password por defecto para todos los usuarios: "1234"');
    }
}
