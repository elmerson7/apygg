<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<File>
     */
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $name = $this->faker->word.'.txt',
            'filename' => $name,
            'path' => 'uploads/'.$name,
            'url' => null,
            'disk' => 'local',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => $this->faker->numberBetween(1024, 10485760), // 1KB to 10MB
            'type' => 'document',
            'category' => 'default',
            'description' => $this->faker->sentence,
            'metadata' => $this->faker->randomElement([null, ['key' => 'value']]),
            'is_public' => false,
            'expires_at' => $this->faker->randomElement([null, $this->faker->dateTimeBetween('now', '+1 year')]),
            'deleted_by' => null,
        ];
    }
}
