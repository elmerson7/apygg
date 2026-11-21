<?php

namespace Tests\Support;

use App\Contracts\SocialIdentityResolverInterface;
use App\Support\Social\SocialIdentity;

/** Resolutor social falso con identidad fija (procesos hijos de los tests de concurrencia). */
final class FakeSocialIdentityResolver implements SocialIdentityResolverInterface
{
    public function __construct(private readonly string $sub, private readonly string $email) {}

    public function resolve(string $provider, array $credentials): SocialIdentity
    {
        return new SocialIdentity($provider, $this->sub, $this->email, true, 'Carrera');
    }
}
