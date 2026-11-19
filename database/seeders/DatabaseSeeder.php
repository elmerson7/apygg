<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder
 *
 * Datos iniciales seguros para cualquier entorno (incluida producción), todos
 * idempotentes:
 * 1. RoleSeeder      - roles base (y, antes, PermissionSeeder con los permisos)
 * 2. AdminUserSeeder - primer administrador desde ADMIN_EMAIL / ADMIN_PASSWORD
 *
 * Los datos de demostración viven aparte (DemoDataSeeder, solo local).
 * MenuSeeder, MailSystemSeeder y LlmAreaSeeder se ejecutan con --class cuando
 * el proyecto use esos módulos.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
