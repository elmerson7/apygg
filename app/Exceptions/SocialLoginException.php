<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Fallos del login social. El mensaje de `social_login_invalid` es genérico a propósito: no dice por qué
 * se rechazó el token (firma, audiencia, caducidad...).
 */
class SocialLoginException extends ApiException
{
    private function __construct(string $message, int $status, private readonly string $errorKey, array $extensions = [])
    {
        parent::__construct($message, $status, [], $errorKey, null, $extensions);
    }

    public static function invalid(): self
    {
        return new self('Credenciales del proveedor inválidas', 401, 'social_login_invalid');
    }

    public static function unavailable(): self
    {
        return new self('Proveedor de login no disponible', 404, 'social_provider_unavailable');
    }

    public static function emailUnverified(): self
    {
        return new self('El proveedor no confirma que el correo sea tuyo: no se puede vincular ni crear la cuenta', 403, 'social_email_unverified');
    }

    public static function notRegistered(): self
    {
        return new self('No existe una cuenta para esta identidad', 403, 'social_account_not_found');
    }

    public static function accountConflict(): self
    {
        return new self('Esa cuenta ya tiene otra identidad de este proveedor vinculada', 409, 'social_account_conflict');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self('Demasiados intentos. Inténtalo más tarde', 429, 'rate_limited', ['retry_after' => $retryAfter]);
    }

    public function errorCode(): ErrorCode|string
    {
        return ErrorCode::tryFrom($this->errorKey) ?? $this->errorKey;
    }
}
