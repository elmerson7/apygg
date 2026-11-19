<?php

namespace App\Http\Resources\Users;

use App\Http\Resources\BaseResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * ProfileResource
 *
 * Perfil del usuario en sesión: datos de cuenta editables + `user_profiles`.
 * Recibe un `User` con la relación `profile` cargada.
 *
 * @property User $resource
 */
class ProfileResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'phone' => $profile?->phone,
            'avatar' => $profile?->avatar,
            'bio' => $profile?->bio,
            'date_of_birth' => $profile?->date_of_birth?->toDateString(),
            'gender' => $profile?->gender,
            'address' => $profile?->address,
            'city' => $profile?->city,
            'state' => $profile?->state,
            'country' => $profile?->country,
            'postal_code' => $profile?->postal_code,
            'social_links' => $profile?->social_links,
            'updated_at' => $this->formatDate($user->updated_at),
        ];
    }
}
