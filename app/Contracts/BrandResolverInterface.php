<?php

namespace App\Contracts;

/**
 * Marca (nombre, logo, colores, dirección legal, soporte, redes) para plantillas de correo.
 * Resolución: ajuste `brand.*` del tenant activo -> ajuste de plataforma -> `config/brand.php`.
 */
interface BrandResolverInterface
{
    /**
     * @return array{name: string, logo_url: ?string, url: string, colors: array<string, string>, legal_address: ?string, support_email: ?string, social: array<string, string>}
     */
    public function get(): array;
}
