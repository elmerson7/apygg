<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\BaseResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AuthTokenResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->resource['access_token'],
            'expires_in' => $this->resource['expires_in'],
            'refresh_token' => $this->resource['refresh_token'],
            'refresh_expires_in' => $this->resource['refresh_expires_in'],
            'user' => UserResource::make($this->resource['user']),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return array_merge(parent::with($request), [
            'links' => [
                'refresh' => route('auth.refresh'),
                'logout' => route('auth.logout'),
                'profile' => route('users.me'),
            ],
        ]);
    }
}
