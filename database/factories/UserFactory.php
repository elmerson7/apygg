<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
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
                $user->roles()->attach($adminRole->id);
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
     * @param  array|Role|string  $roles
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
                $user->roles()->attach($roleIds);
            }
        });
    }

    /**
     * Attach permissions to the user after creation.
     *
     * @param  array|Permission|string  $permissions
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
                $user->permissions()->attach($permissionIds);
            }
        });
    }
}
