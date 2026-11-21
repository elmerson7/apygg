<?php

namespace App\Contracts;

use App\Exceptions\SocialLoginException;
use App\Support\Social\SocialIdentity;

/**
 * Convierte las credenciales que trae la app (id_token o access_token del proveedor) en una identidad verificada.
 * En pruebas se sustituye por un proveedor falso: sin red.
 */
interface SocialIdentityResolverInterface
{
    /**
     * @param  array{id_token?: string|null, access_token?: string|null, nonce?: string|null}  $credentials
     *
     * @throws SocialLoginException `social_login_invalid` si no se puede verificar
     */
    public function resolve(string $provider, array $credentials): SocialIdentity;
}
