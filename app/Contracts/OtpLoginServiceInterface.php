<?php

namespace App\Contracts;

use App\Exceptions\OtpLoginException;

/**
 * Login sin contraseña por código de un solo uso (módulo `otp-login`).
 */
interface OtpLoginServiceInterface
{
    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_SMS = 'sms';

    /**
     * Genera y envía (en cola) un código si el destino pertenece a un usuario que puede entrar. No devuelve nada
     * que distinga si existe: el llamador responde siempre lo mismo.
     *
     * @return int segundos de validez de un código
     *
     * @throws OtpLoginException `otp_login_unavailable`, `rate_limited`
     */
    public function request(string $channel, string $destination, ?string $ipAddress = null): int;

    /**
     * Canjea el código por la sesión (misma puerta que el login normal: 2FA, lista negra, sesiones).
     *
     * @return array<string, mixed> como `AuthServiceInterface::authenticateVerified`
     *
     * @throws OtpLoginException `otp_invalid`, `otp_login_unavailable`, `rate_limited`
     */
    public function verify(string $channel, string $destination, string $code, ?string $ipAddress = null): array;
}
