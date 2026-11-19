<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DemoDataSeeder
 *
 * Datos de demostración SOLO para desarrollo local: un usuario por rol base y
 * unos cuantos usuarios normales, todos con la contraseña "password".
 *
 * No lo llama DatabaseSeeder en ningún entorno; se ejecuta a propósito:
 *   make art cmd="db:seed --class=DemoDataSeeder"
 * Se niega a correr en producción. Idempotente (por email).
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoDataSeeder no se ejecuta en producción.');

            return;
        }

        $this->call(RoleSeeder::class);

        $users = [
            ['Demo Admin', 'admin@demo.test', ['admin']],
            ['Demo Manager', 'manager@demo.test', ['manager']],
            ['Demo User', 'user@demo.test', ['user']],
            ['Alice Williams', 'alice@demo.test', ['user']],
            ['Bob Johnson', 'bob@demo.test', ['user']],
            ['Charlie Brown', 'charlie@demo.test', ['user']],
        ];

        foreach ($users as [$name, $email, $roles]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => self::PASSWORD]
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $user->assignRole($roles);
        }

        $this->command->info('Usuarios demo creados ('.count($users).'). Contraseña: "'.self::PASSWORD.'"');
    }
}
