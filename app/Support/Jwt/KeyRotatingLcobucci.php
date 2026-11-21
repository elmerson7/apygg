<?php

namespace App\Support\Jwt;

use DateTimeImmutable;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWithOneInSet;
use Lcobucci\JWT\Validation\Constraint\SignedWithUntilDate;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci;

/**
 * Proveedor JWT opcional para algoritmos asimétricos (RS256/ES256...): añade la cabecera `kid` a los tokens que
 * firma y acepta, además de la clave pública vigente, la ANTERIOR hasta `JWT_PREVIOUS_PUBLIC_KEY_UNTIL`
 * (rotación sin cerrar sesiones). Solo se activa con `JWT_KID` (config/jwt.php); sin él se usa el proveedor
 * de la librería, sin cambios. Ver docs/jwt-keys.md.
 */
class KeyRotatingLcobucci extends Lcobucci
{
    public function __construct()
    {
        parent::__construct(config('jwt.secret'), config('jwt.algo'), (array) config('jwt.keys'));

        $current = new SignedWithUntilDate($this->signer, $this->getVerificationKey(), new DateTimeImmutable('9999-12-31'));
        $constraints = [$current];

        $previous = config('jwt.previous_public_key');
        $until = config('jwt.previous_public_key_until');

        if ($this->isAsymmetric() && is_string($previous) && $previous !== '' && is_string($until) && $until !== '') {
            $constraints[] = new SignedWithUntilDate(
                $this->signer,
                InMemory::plainText($previous),
                new DateTimeImmutable($until),
            );
        }

        $this->config->setValidationConstraints(new SignedWithOneInSet(...$constraints));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload)
    {
        $this->builder = $this->config->builder();

        try {
            foreach ($payload as $key => $value) {
                $this->builder = $this->addClaim($key, $value);
            }

            $kid = (string) config('jwt.kid', '');

            if ($kid !== '') {
                $this->builder = $this->builder->withHeader('kid', $kid);
            }

            return $this->builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
        } catch (\Exception $e) {
            throw new JWTException('Could not create token: '.$e->getMessage(), $e->getCode(), $e);
        }
    }
}
