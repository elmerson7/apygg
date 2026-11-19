<?php

namespace App\Contracts;

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SocialAuthServiceInterface
 *
 * Contrato del login social.
 */
interface SocialAuthServiceInterface
{
    /**
     * Encuentra o crea un usuario a partir del perfil social.
     * Nunca expone el token social en logs.
     */
    public function findOrCreateUser(SocialiteUser $socialUser, string $provider): User;

    /**
     * Genera tokens JWT para el usuario social.
     */
    public function generateTokens(User $user): array;
}
