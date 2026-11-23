<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
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
        $name = fake()->word().'_'.Str::lower(Str::random(6));

        return [
            'name' => strtolower($name),
            'display_name' => ucfirst($name),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Attach permissions to the role after creation.
     *
     * @param  list<string|Permission>|Permission|string  $permissions
     */
    public function withPermissions($permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions) {
            $permissionIds = collect(is_array($permissions) ? $permissions : [$permissions])->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return $permission->id;
                }

                return Permission::where('name', $permission)->first()?->id;
            })->filter()->toArray();

            if (! empty($permissionIds)) {
                $role->givePermissionTo($permissionIds);
            }
        });
    }
}
