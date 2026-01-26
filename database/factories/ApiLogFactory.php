<?php

namespace Database\Factories;

use App\Models\Logs\ApiLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Logs\ApiLog>
 */
class ApiLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Logs\ApiLog>
     */
    protected $model = ApiLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trace_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'request_method' => fake()->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            'request_path' => '/api/'.fake()->word(),
            'request_query' => [],
            'request_body' => null,
            'request_headers' => [
                'content-type' => ['application/json'],
                'accept' => ['application/json'],
            ],
            'response_status' => fake()->randomElement([200, 201, 400, 401, 403, 404, 500]),
            'response_body' => null,
            'response_time_ms' => fake()->numberBetween(10, 5000),
            'user_agent' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Indicate that the log is for a successful response.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_status' => fake()->randomElement([200, 201]),
        ]);
    }

    /**
     * Indicate that the log is for an error response.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_status' => fake()->randomElement([400, 401, 403, 404, 500]),
        ]);
    }

    /**
     * Indicate that the log is for a slow request.
     */
    public function slow(int $thresholdMs = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'response_time_ms' => fake()->numberBetween($thresholdMs + 1, 10000),
        ]);
    }

    /**
     * Set the trace ID.
     */
    public function withTraceId(string $traceId): static
    {
        return $this->state(fn (array $attributes) => [
            'trace_id' => $traceId,
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

    /**
     * Set the request method and path.
     */
    public function forEndpoint(string $method, string $path): static
    {
        return $this->state(fn (array $attributes) => [
            'request_method' => strtoupper($method),
            'request_path' => $path,
        ]);
    }
}
