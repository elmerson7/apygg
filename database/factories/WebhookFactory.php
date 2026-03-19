<?php

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Webhook::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company,
            'url' => $this->faker->url,
            'events' => $this->faker->randomElements(['user.created', 'user.updated', 'role.assigned'], 2),
            'status' => $this->faker->randomElement(['active', 'inactive', 'paused']),
            'timeout' => $this->faker->numberBetween(5, 300),
            'max_retries' => $this->faker->numberBetween(1, 10),
            'secret' => $this->faker->sha256,
            'old_secret' => null,
            'secret_rotated_at' => null,
        ];
    }
}