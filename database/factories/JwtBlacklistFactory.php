<?php

namespace Database\Factories;

use App\Models\JwtBlacklist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * @extends Factory<JwtBlacklist>
 */
class JwtBlacklistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = JwtBlacklist::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jti' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'expires_at' => Carbon::now()->addHours(24), // Tokens expiran en 24 horas por defecto
        ];
    }
}