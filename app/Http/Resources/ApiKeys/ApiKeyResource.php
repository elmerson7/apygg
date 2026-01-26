<?php

namespace App\Http\Resources\ApiKeys;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * ApiKeyResource
 *
 * Resource para transformación de datos de API Key.
 * NUNCA incluye el campo 'key' (hash) en respuestas normales.
 * Solo se incluye la key completa en la respuesta de creación.
 */
class ApiKeyResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $apiKey = $this->resource;

        return array_merge($this->getBaseFields(), [
            'name' => $apiKey->name,
            'scopes' => $apiKey->scopes ?? [],
            'last_used_at' => $this->formatDate($apiKey->last_used_at),
            'expires_at' => $this->formatDate($apiKey->expires_at),
            'is_active' => $apiKey->isActive(),
            'is_expired' => $apiKey->isExpired(),

            // Relación con usuario (solo si se carga)
            'user' => $this->whenLoaded('user', function () use ($apiKey) {
                return [
                    'id' => $apiKey->user->id,
                    'name' => $apiKey->user->name,
                    'email' => $apiKey->user->email,
                ];
            }),

            // Timestamps
            'created_at' => $this->formatDate($apiKey->created_at),
            'updated_at' => $this->formatDate($apiKey->updated_at),
        ]);
    }
}
