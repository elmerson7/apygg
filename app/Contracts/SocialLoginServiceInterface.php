<?php

namespace App\Contracts;

use App\Exceptions\SocialLoginException;

/**
 * Login social (módulo `social-login`): canjea una identidad del proveedor por la sesión de la API.
 */
interface SocialLoginServiceInterface
{
    /**
     * @param  array{id_token?: string|null, access_token?: string|null, nonce?: string|null}  $credentials
     * @return array<string, mixed> como `AuthServiceInterface::authenticateVerified` (tokens o desafío 2FA)
     *
     * @throws SocialLoginException
     */
    public function exchange(string $provider, array $credentials, ?string $ipAddress = null): array;

    /** ¿Está activo el módulo y configurado ese proveedor? */
    public function isAvailable(string $provider): bool;
}
