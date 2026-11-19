<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{user: array{id: int, name: string, email: string, email_verified_at: string|null}, access_token: string, token_type: string, expires_in: int, refresh_token?: string}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        $data = [
            'user' => [
                /** @var int */
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'access_token' => $this->resource['access_token'],
            'token_type' => $this->resource['token_type'] ?? 'bearer',
            /** @var int */
            'expires_in' => $this->resource['expires_in'] ?? config('jwt.ttl') * 60,
        ];

        if (isset($this->resource['refresh_token'])) {
            $data['refresh_token'] = $this->resource['refresh_token'];
        }

        return $data;
    }
}
