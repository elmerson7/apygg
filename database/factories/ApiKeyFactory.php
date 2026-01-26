<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ApiKey>
     */
    protected $model = ApiKey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generar key completa con prefijo
        $environment = $this->faker->randomElement(['live', 'test']);
        $prefix = config('api-keys.prefixes.'.$environment, 'apygg_live_');
        $randomKey = Str::random(config('api-keys.key_length', 64));
        $fullKey = $prefix.$randomKey;

        // Hash de la key completa
        $hashedKey = hash('sha256', $fullKey);

        // Scopes disponibles
        $availableScopes = config('api-keys.available_scopes', [
            'users.read',
            'users.write',
            'roles.read',
            'permissions.read',
        ]);

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true).' Key',
            'key' => $hashedKey,
            'scopes' => $this->faker->randomElements($availableScopes, $this->faker->numberBetween(1, 3)),
            'expires_at' => $this->faker->optional(0.3)->dateTimeBetween('now', '+1 year'),
            'last_used_at' => $this->faker->optional(0.5)->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate that the API Key is for live environment.
     */
    public function live(): static
    {
        return $this->state(function (array $attributes) {
            $prefix = config('api-keys.prefixes.live', 'apygg_live_');
            $randomKey = Str::random(config('api-keys.key_length', 64));
            $fullKey = $prefix.$randomKey;

            return [
                'key' => hash('sha256', $fullKey),
            ];
        });
    }

    /**
     * Indicate that the API Key is for test environment.
     */
    public function test(): static
    {
        return $this->state(function (array $attributes) {
            $prefix = config('api-keys.prefixes.test', 'apygg_test_');
            $randomKey = Str::random(config('api-keys.key_length', 64));
            $fullKey = $prefix.$randomKey;

            return [
                'key' => hash('sha256', $fullKey),
            ];
        });
    }

    /**
     * Indicate that the API Key has no expiration.
     */
    public function neverExpires(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => null,
        ]);
    }

    /**
     * Indicate that the API Key is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $this->faker->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }

    /**
     * Indicate that the API Key has all scopes (wildcard).
     */
    public function withAllScopes(): static
    {
        return $this->state(fn (array $attributes) => [
            'scopes' => ['*'],
        ]);
    }

    /**
     * Indicate that the API Key has no scopes (empty array).
     */
    public function withoutScopes(): static
    {
        return $this->state(fn (array $attributes) => [
            'scopes' => [],
        ]);
    }
}
