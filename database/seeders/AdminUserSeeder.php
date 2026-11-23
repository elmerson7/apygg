<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * AdminUserSeeder
 *
 * Crea el primer administrador desde el entorno (config auth.initial_admin):
 * ADMIN_EMAIL, ADMIN_PASSWORD y, opcional, ADMIN_NAME.
 *
 * - Sin ADMIN_EMAIL: no hace nada.
 * - Sin ADMIN_PASSWORD en producción: no crea el usuario y avisa (nunca hay una
 *   contraseña por defecto). Fuera de producción genera una al azar y la muestra
 *   una sola vez por consola.
 * - Idempotente: si el usuario ya existe no toca su contraseña; solo se asegura
 *   de que tenga el rol admin.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('auth.initial_admin.email');

        if (! $email) {
            $this->command?->warn('ADMIN_EMAIL no definido: no se crea el administrador inicial.');

            return;
        }

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $password = config('auth.initial_admin.password');
            $generated = false;

            if (! $password) {
                if (app()->environment('production')) {
                    $this->command?->error('ADMIN_PASSWORD no definida en producción: el administrador inicial NO se crea.');

                    return;
                }

                $password = Str::password(20);
                $generated = true;
            }

            $user = User::create([
                'name' => config('auth.initial_admin.name') ?: 'Administrador',
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($generated) {
                $this->command?->warn("Contraseña generada para {$email} (se muestra solo ahora): {$password}");
            }
        }

        $adminRole = Role::where('name', 'admin')->first();

        if (! $adminRole) {
            $this->command?->warn('Rol admin inexistente: ejecuta RoleSeeder antes.');

            return;
        }

        $user->assignRole($adminRole);

        $this->command?->info("Administrador inicial: {$email}");
    }
}
