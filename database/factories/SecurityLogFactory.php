<?php

namespace Database\Factories;

use App\Models\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Logs\SecurityLog>
 */
class SecurityLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Logs\SecurityLog>
     */
    protected $model = SecurityLog::class;

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
            'event_type' => fake()->randomElement([
                SecurityLog::EVENT_LOGIN_SUCCESS,
                SecurityLog::EVENT_LOGIN_FAILURE,
                SecurityLog::EVENT_PERMISSION_DENIED,
                SecurityLog::EVENT_SUSPICIOUS_ACTIVITY,
                SecurityLog::EVENT_PASSWORD_CHANGED,
                SecurityLog::EVENT_TOKEN_REVOKED,
                SecurityLog::EVENT_ACCOUNT_LOCKED,
                SecurityLog::EVENT_ACCOUNT_UNLOCKED,
            ]),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'details' => [
                'description' => fake()->sentence(),
            ],
        ];
    }

    /**
     * Indicate that the log is for a login success.
     */
    public function loginSuccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => SecurityLog::EVENT_LOGIN_SUCCESS,
            'details' => [
                'login_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Indicate that the log is for a login failure.
     */
    public function loginFailure(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => SecurityLog::EVENT_LOGIN_FAILURE,
            'user_id' => null,
            'details' => [
                'email' => fake()->safeEmail(),
                'reason' => 'Invalid credentials',
                'attempted_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Indicate that the log is for a permission denied.
     */
    public function permissionDenied(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => SecurityLog::EVENT_PERMISSION_DENIED,
            'details' => [
                'permission' => fake()->word().'.'.fake()->word(),
                'resource' => fake()->word(),
                'denied_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Indicate that the log is for suspicious activity.
     */
    public function suspiciousActivity(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => SecurityLog::EVENT_SUSPICIOUS_ACTIVITY,
            'details' => [
                'description' => fake()->sentence(),
                'detected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Indicate that the log is for account locked.
     */
    public function accountLocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => SecurityLog::EVENT_ACCOUNT_LOCKED,
            'details' => [
                'reason' => fake()->sentence(),
                'locked_at' => now()->toIso8601String(),
            ],
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
     * Set the event type.
     */
    public function withEventType(string $eventType): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => $eventType,
        ]);
    }
}
