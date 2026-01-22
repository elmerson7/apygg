<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->resource['user']->id,
                'name' => $this->resource['user']->name,
                'email' => $this->resource['user']->email,
                'email_verified_at' => $this->resource['user']->email_verified_at?->toIso8601String(),
            ],
            'access_token' => $this->resource['access_token'],
            'token_type' => $this->resource['token_type'] ?? 'bearer',
            'expires_in' => $this->resource['expires_in'] ?? config('jwt.ttl') * 60, // En segundos
        ];
    }
}
