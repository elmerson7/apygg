<?php

namespace Database\Factories;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Settings>
 */
class SettingsFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Settings::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->word,
            'value' => $this->faker->sentence,
            'type' => $this->faker->randomElement(['string', 'integer', 'boolean', 'json', 'array']),
            'group' => $this->faker->randomElement(['app', 'system', 'security', 'payment', 'notification']),
            'description' => $this->faker->optional()->sentence,
            'is_public' => $this->faker->boolean(25), // 25% chance of being public
            'is_encrypted' => $this->faker->boolean(10), // 10% chance of being encrypted
        ];
    }
}