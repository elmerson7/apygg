<?php

namespace App\Http\Resources\Auth;

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
        $user = $this->resource['user'];

        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'access_token' => $this->resource['access_token'],
            'token_type' => $this->resource['token_type'] ?? 'bearer',
            'expires_in' => $this->resource['expires_in'] ?? config('jwt.ttl') * 60,
        ];

        if (isset($this->resource['refresh_token'])) {
            $data['refresh_token'] = $this->resource['refresh_token'];
        }

        return $data;
    }
}
