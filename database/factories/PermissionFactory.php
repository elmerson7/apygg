<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resource = fake()->word();
        $action = fake()->randomElement(['create', 'read', 'update', 'delete', 'manage']);
        $name = "{$resource}.{$action}";

        return [
            'name' => $name,
            'display_name' => ucfirst($action).' '.ucfirst($resource),
            'resource' => $resource,
            'action' => $action,
            'description' => "Permite {$action} {$resource}",
        ];
    }

    /**
     * Create a permission for a specific resource and action.
     */
    public function forResource(string $resource, string $action = 'read'): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => "{$resource}.{$action}",
            'display_name' => ucfirst($action).' '.ucfirst($resource),
            'resource' => $resource,
            'action' => $action,
            'description' => "Permite {$action} {$resource}",
        ]);
    }
}
