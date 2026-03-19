<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
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
            'name' => $this->faker->fileName,
            'filename' => $this->faker->fileName,
            'path' => $this->faker->filePath,
            'url' => $this->faker->url,
            'disk' => $this->faker->randomElement(['local', 's3', 'minio']),
            'mime_type' => $this->faker->randomElement([
                'image/jpeg',
                'image/png',
                'application/pdf',
                'text/plain',
                'application/json'
            ]),
            'extension' => $this->faker->fileExtension,
            'size' => $this->faker->numberBetween(1024, 10485760), // 1KB to 10MB
            'type' => $this->faker->word,
            'category' => $this->faker->word,
            'description' => $this->faker->sentence,
            'metadata' => $this->faker->randomElement([null, ['key' => 'value']]),
            'is_public' => $this->faker->boolean,
            'expires_at' => $this->faker->randomElement([null, $this->faker->dateTimeBetween('now', '+1 year')]),
            'deleted_by' => null,
        ];
    }
}