<?php

namespace Database\Factories;

use App\Models\Logs\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Logs\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = \App\Models\Logs\ActivityLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'model_type' => User::class,
            'model_id' => \Illuminate\Support\Str::uuid()->toString(),
            'action' => fake()->randomElement([
                ActivityLog::ACTION_CREATED,
                ActivityLog::ACTION_UPDATED,
                ActivityLog::ACTION_DELETED,
                ActivityLog::ACTION_RESTORED,
            ]),
            'old_values' => null,
            'new_values' => [
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
            ],
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Indicate that the log is for a created action.
     */
    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => ActivityLog::ACTION_CREATED,
            'old_values' => null,
        ]);
    }

    /**
     * Indicate that the log is for an updated action.
     */
    public function updated(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => ActivityLog::ACTION_UPDATED,
            'old_values' => [
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
            ],
            'new_values' => [
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
            ],
        ]);
    }

    /**
     * Indicate that the log is for a deleted action.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => ActivityLog::ACTION_DELETED,
            'old_values' => null,
        ]);
    }

    /**
     * Indicate that the log is for a restored action.
     */
    public function restored(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => ActivityLog::ACTION_RESTORED,
            'old_values' => null,
        ]);
    }

    /**
     * Set the model type and ID.
     */
    public function forModel(string $modelType, string $modelId): static
    {
        return $this->state(fn (array $attributes) => [
            'model_type' => $modelType,
            'model_id' => $modelId,
        ]);
    }

    /**
     * Set the user ID.
     */
    public function forUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }
}
