<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * UserSeeder
 *
 * Seeder para crear usuarios de prueba con diferentes roles.
 * Password por defecto para todos los usuarios: "password"
 *
 * @package Database\Seeders
 */
class UserSeeder extends Seeder
{
    /**
     * Password por defecto para todos los usuarios
     */
    protected const DEFAULT_PASSWORD = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener roles
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

        // Definir usuarios de prueba
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@apygg.com',
                'email_verified_at' => now(),
                'roles' => [$adminRole],
            ],
            [
                'name' => 'Manager User',
                'email' => 'manager@apygg.com',
                'email_verified_at' => now(),
                'roles' => [$managerRole],
            ],
            [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole, $editorRole],
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane.smith@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole, $moderatorRole],
            ],
            [
                'name' => 'Bob Johnson',
                'email' => 'bob.johnson@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole],
            ],
            [
                'name' => 'Alice Williams',
                'email' => 'alice.williams@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole],
            ],
            [
                'name' => 'Charlie Brown',
                'email' => 'charlie.brown@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole],
            ],
            [
                'name' => 'Diana Prince',
                'email' => 'diana.prince@example.com',
                'email_verified_at' => now(),
                'roles' => [$userRole],
            ],
            [
                'name' => 'Moderator User',
                'email' => 'moderator@apygg.com',
                'email_verified_at' => now(),
                'roles' => [$moderatorRole],
            ],
            [
                'name' => 'Editor User',
                'email' => 'editor@apygg.com',
                'email_verified_at' => now(),
                'roles' => [$editorRole],
            ],
            [
                'name' => 'Guest User',
                'email' => 'guest@apygg.com',
                'email_verified_at' => null,
                'roles' => [$guestRole],
            ],
            [
                'name' => 'Test User',
                'email' => 'test@apygg.com',
                'email_verified_at' => now(),
                'roles' => [$userRole],
            ],
        ];

        // Crear usuarios
        foreach ($users as $userData) {
            $roles = $userData['roles'];
            unset($userData['roles']);

            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                array_merge($userData, [
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );

            // Asignar roles
            if (!empty($roles)) {
                $user->roles()->syncWithoutDetaching(
                    collect($roles)->pluck('id')->toArray()
                );
            }
        }

        // Asignar permisos directos adicionales al Test User
        $testUser = User::where('email', 'test@apygg.com')->first();
        if ($testUser) {
            $postsDeleteAny = \App\Models\Permission::where('name', 'posts.delete-any')->first();
            $commentsModerate = \App\Models\Permission::where('name', 'comments.moderate')->first();

            if ($postsDeleteAny) {
                $testUser->permissions()->syncWithoutDetaching([$postsDeleteAny->id]);
            }
            if ($commentsModerate) {
                $testUser->permissions()->syncWithoutDetaching([$commentsModerate->id]);
            }
        }

        $this->command->info('Usuarios de prueba creados: ' . count($users) . ' usuarios');
    }
}
