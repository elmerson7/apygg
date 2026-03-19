<?php

namespace Database\Factories;

use App\Models\WebhookDelivery;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WebhookDelivery::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'url' => $this->faker->url,
            'http_method' => $this->faker->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            'request_headers' => $this->faker->randomElement([
                null,
                ['Content-Type' => 'application/json'],
                ['Authorization' => 'Bearer ' . $this->faker->sha256]
            ]),
            'request_payload' => $this->faker->randomElement([
                null,
                ['test' => 'data'],
                ['event' => 'user.created', 'data' => ['id' => 123]]
            ]),
            'response_status' => $this->faker->randomElement([200, 201, 400, 401, 403, 404, 500]),
            'response_headers' => $this->faker->randomElement([
                null,
                ['Content-Type' => 'application/json']
            ]),
            'response_body' => $this->faker->randomElement([
                null,
                '{"success": true}',
                '{"error": "Not found"}'
            ]),
            'delivered_at' => $this->faker->randomElement([null, $this->faker->dateTime]),
            'error_message' => $this->faker->randomElement([null, 'Connection timeout', 'Invalid response']),
        ];
    }
}