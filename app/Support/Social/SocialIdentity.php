<?php

namespace App\Support\Social;

/**
 * Identidad ya verificada por el proveedor (firma del id_token o perfil con un token comprobado).
 * `emailVerified` es la única base para vincular por email: nunca se deduce de que el email exista.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name = null,
    ) {}
}
