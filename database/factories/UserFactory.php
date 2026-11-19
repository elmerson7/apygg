<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Documentos ya generados en este proceso (identity_document es único en BD).
     *
     * @var array<int, true>
     */
    protected static array $usedDocuments = [];

    /**
     * Email único de verdad: `fake()->unique()` se reinicia en cada test y solo
     * conoce lo que generó él mismo, así que puede chocar con filas ya existentes
     * (datos sembrados, tests que no revierten). El sufijo aleatorio lo evita.
     */
    protected static function uniqueEmail(): string
    {
        return strtolower(fake()->userName().'.'.Str::random(8).'@example.com');
    }

    /**
     * Documento de 10 dígitos que no se repite dentro del proceso y casi nunca fuera de él.
     */
    protected static function uniqueIdentityDocument(): string
    {
        do {
            $document = random_int(1_000_000_000, 9_999_999_999);
        } while (isset(static::$usedDocuments[$document]));

        static::$usedDocuments[$document] = true;

        return (string) $document;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => static::uniqueEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'identity_document' => fake()->boolean() ? static::uniqueIdentityDocument() : null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user) {
            $adminRole = Role::where('name', 'admin')->first();
            if ($adminRole) {
                $user->assignRole($adminRole);
            }
        });
    }

    /**
     * Indicate that the user is inactive (soft deleted).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Indicate that the user's email is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Attach roles to the user after creation.
     *
     * @param  list<string|Role>|Role|string  $roles
     */
    public function withRoles($roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles) {
            $roleIds = collect($roles)->map(function ($role) {
                if ($role instanceof Role) {
                    return $role->id;
                }
                if (is_string($role)) {
                    $roleModel = Role::where('name', $role)->first();

                    return $roleModel ? $roleModel->id : null;
                }

                return null;
            })->filter()->toArray();

            if (! empty($roleIds)) {
                $user->assignRole($roleIds);
            }
        });
    }

    /**
     * Attach permissions to the user after creation.
     *
     * @param  list<string|Permission>|Permission|string  $permissions
     */
    public function withPermissions($permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions) {
            $permissionIds = collect($permissions)->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return $permission->id;
                }
                if (is_string($permission)) {
                    $permissionModel = Permission::where('name', $permission)->first();

                    return $permissionModel ? $permissionModel->id : null;
                }

                return null;
            })->filter()->toArray();

            if (! empty($permissionIds)) {
                $user->givePermissionTo($permissionIds);
            }
        });
    }
}
