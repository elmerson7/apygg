<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAuthService
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Encuentra o crea un usuario a partir del perfil social.
     * Nunca expone el token social en logs.
     */
    public function findOrCreateUser(SocialiteUser $socialUser, string $provider): User
    {
        $user = User::where('email', $socialUser->getEmail())->first();

        if ($user) {
            // Actualizar provider si no lo tenía
            if (! $user->provider) {
                $user->update([
                    'provider'    => $provider,
                    'provider_id' => $socialUser->getId(),
                ]);
            }

            return $user;
        }

        return User::create([
            'name'        => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Usuario',
            'email'       => $socialUser->getEmail(),
            'password'    => Hash::make(Str::random(32)),
            'provider'    => $provider,
            'provider_id' => $socialUser->getId(),
        ]);
    }

    /**
     * Genera tokens JWT para el usuario social.
     */
    public function generateTokens(User $user): array
    {
        return $this->authService->generateTokens($user);
    }
}
