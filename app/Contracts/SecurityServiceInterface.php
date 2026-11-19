<?php

namespace App\Contracts;

use Illuminate\Support\Facades\Hash;

/**
 * SecurityServiceInterface
 *
 * Contrato del servicio de operaciones de seguridad (cifrado, IPs, tokens).
 */
interface SecurityServiceInterface
{
    /**
     * Encriptar datos sensibles
     *
     * @param  mixed  $value  Valor a encriptar
     * @return string Valor encriptado
     */
    public function encrypt($value): string;

    /**
     * Desencriptar datos
     *
     * @param  string  $encryptedValue  Valor encriptado
     * @return mixed Valor desencriptado
     */
    public function decrypt(string $encryptedValue);

    /**
     * Hash de contraseña usando bcrypt
     *
     * @param  string  $password  Contraseña en texto plano
     * @param  int  $rounds  Número de rounds (default: 10)
     * @return string Hash de contraseña
     */
    public function hashPassword(string $password, int $rounds = 10): string;

    /**
     * Verificar contraseña
     *
     * @param  string  $password  Contraseña en texto plano
     * @param  string  $hash  Hash almacenado
     */
    public function verifyPassword(string $password, string $hash): bool;

    /**
     * Verificar si IP está en whitelist
     *
     * @param  string  $ip  IP a verificar
     * @param  array|null  $whitelist  Lista de IPs permitidas (null = usar config)
     */
    public function isIpWhitelisted(string $ip, ?array $whitelist = null): bool;

    /**
     * Detectar comportamiento sospechoso
     *
     * @param  string  $ip  IP del usuario
     * @param  string  $action  Acción realizada
     * @param  array  $context  Contexto adicional
     * @return array ['is_suspicious' => bool, 'reasons' => array]
     */
    public function detectSuspiciousBehavior(string $ip, string $action, array $context = []): array;

    /**
     * Generar token seguro
     *
     * @param  int  $length  Longitud del token
     * @return string Token generado
     */
    public function generateSecureToken(int $length = 64): string;

    /**
     * Generar token para reset de contraseña
     *
     * @return string Token único
     */
    public function generatePasswordResetToken(): string;

    /**
     * Validar token de reset de contraseña
     *
     * @param  string  $token  Token a validar
     * @param  string  $storedToken  Token almacenado
     */
    public function validatePasswordResetToken(string $token, string $storedToken): bool;

    /**
     * Sanitizar entrada HTML
     *
     * @param  string  $input  Entrada a sanitizar
     * @return string Entrada sanitizada
     */
    public function sanitizeHtml(string $input): string;

    /**
     * Validar CSRF token
     *
     * @param  string  $token  Token a validar
     */
    public function validateCsrfToken(string $token): bool;

    /**
     * Registrar acción fallida
     */
    public function recordFailedAction(string $ip, string $action): void;
}
