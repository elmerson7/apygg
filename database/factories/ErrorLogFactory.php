<?php

namespace Database\Factories;

use App\Models\Logs\ErrorLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Logs\ErrorLog>
 */
class ErrorLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = \App\Models\Logs\ErrorLog::class;

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
            'exception_class' => fake()->randomElement([
                \InvalidArgumentException::class,
                \RuntimeException::class,
                \Illuminate\Database\QueryException::class,
                \Illuminate\Validation\ValidationException::class,
            ]),
            'message' => fake()->sentence(),
            'file' => fake()->filePath(),
            'line' => fake()->numberBetween(1, 1000),
            'stack_trace' => fake()->text(500),
            'context' => [
                'key1' => fake()->word(),
                'key2' => fake()->numberBetween(1, 100),
            ],
            'severity' => fake()->randomElement([
                ErrorLog::SEVERITY_LOW,
                ErrorLog::SEVERITY_MEDIUM,
                ErrorLog::SEVERITY_HIGH,
                ErrorLog::SEVERITY_CRITICAL,
            ]),
            'resolved_at' => null,
        ];
    }

    /**
     * Indicate that the error is low severity.
     */
    public function low(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => ErrorLog::SEVERITY_LOW,
        ]);
    }

    /**
     * Indicate that the error is medium severity.
     */
    public function medium(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => ErrorLog::SEVERITY_MEDIUM,
        ]);
    }

    /**
     * Indicate that the error is high severity.
     */
    public function high(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => ErrorLog::SEVERITY_HIGH,
        ]);
    }

    /**
     * Indicate that the error is critical severity.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => ErrorLog::SEVERITY_CRITICAL,
        ]);
    }

    /**
     * Indicate that the error is resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now(),
        ]);
    }

    /**
     * Indicate that the error is unresolved.
     */
    public function unresolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => null,
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
}
