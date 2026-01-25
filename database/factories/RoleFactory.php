<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => strtolower($name),
            'display_name' => ucfirst($name),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Attach permissions to the role after creation.
     *
     * @param  array|Permission|string  $permissions
     */
    public function withPermissions($permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions) {
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
                $role->permissions()->attach($permissionIds);
            }
        });
    }
}
