<?php

namespace App\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WebhookResource
 *
 * Resource para transformar modelos Webhook en respuestas API.
 */
class WebhookResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $webhook = $this->resource;

        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'status' => $webhook->status,
            'timeout' => $webhook->timeout,
            'max_retries' => $webhook->max_retries,
            'success_count' => $webhook->success_count,
            'failure_count' => $webhook->failure_count,
            'last_triggered_at' => $webhook->last_triggered_at?->toIso8601String(),
            'last_success_at' => $webhook->last_success_at?->toIso8601String(),
            'last_failure_at' => $webhook->last_failure_at?->toIso8601String(),
            'secret_rotated_at' => $webhook->secret_rotated_at?->toIso8601String(),
            'has_old_secret' => ! empty($webhook->old_secret),
            'created_at' => $webhook->created_at->toIso8601String(),
            'updated_at' => $webhook->updated_at->toIso8601String(),

            // Relaciones opcionales
            'user' => $this->whenLoaded('user', function () use ($webhook) {
                return [
                    'id' => $webhook->user->id,
                    'name' => $webhook->user->name,
                    'email' => $webhook->user->email,
                ];
            }),

            'deliveries_count' => $this->when($webhook->relationLoaded('deliveries'), function () use ($webhook) {
                return $webhook->deliveries->count();
            }, function () use ($webhook) {
                return $webhook->deliveries()->count();
            }),
        ];
    }
}
