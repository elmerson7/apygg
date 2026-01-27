<?php

namespace App\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WebhookDeliveryResource
 *
 * Resource para transformar modelos WebhookDelivery en respuestas API.
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $delivery = $this->resource;

        return [
            'id' => $delivery->id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'response_code' => $delivery->response_code,
            'response_body' => $delivery->response_body,
            'error_message' => $delivery->error_message,
            'attempts' => $delivery->attempts,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
            'created_at' => $delivery->created_at->toIso8601String(),
            'updated_at' => $delivery->updated_at->toIso8601String(),

            // Payload (puede ser grande, incluir solo si se solicita)
            'payload' => $this->when($request->get('include_payload', false), $delivery->payload),

            // Relaciones opcionales
            'webhook' => $this->whenLoaded('webhook', function () use ($delivery) {
                return [
                    'id' => $delivery->webhook->id,
                    'name' => $delivery->webhook->name,
                    'url' => $delivery->webhook->url,
                ];
            }),
        ];
    }
}
