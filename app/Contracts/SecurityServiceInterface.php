<?php

namespace App\Contracts;

/**
 * SecurityServiceInterface
 *
 * Contrato para el servicio de operaciones de seguridad.
 */
interface SecurityServiceInterface
{
    /**
     * Encriptar datos sensibles
     *
     * @param  mixed  $value  Valor a encriptar
     * @return string Valor encriptado
     */
    public static function encrypt($value): string;

    /**
     * Desencriptar datos
     *
     * @param  string  $encryptedValue  Valor encriptado
     * @return mixed Valor desencriptado
     */
    public static function decrypt(string $encryptedValue);

    /**
     * Hash de contraseña usando bcrypt
     *
     * @param  string  $password  Contraseña en texto plano
     * @param  int  $rounds  Número de rounds (default: 10)
     * @return string Hash de contraseña
     */
    public static function hashPassword(string $password, int $rounds = 10): string;

    /**
     * Verificar contraseña
     *
     * @param  string  $password  Contraseña en texto plano
     * @param  string  $hash  Hash almacenado
     * @return bool  True si la contraseña coincide con el hash
     */
    public static function verifyPassword(string $password, string $hash): bool;

    /**
     * Verificar si IP está en whitelist
     *
     * @param  string  $ip  IP a verificar
     * @param  array|null  $whitelist  Lista de IPs permitidas (null = usar config)
     * @return bool  True si la IP está en la whitelist
     */
    public static function isIpWhitelisted(string $ip, ?array $whitelist = null): bool;

    /**
     * Detectar comportamiento sospechoso
     *
     * @param  string  $ip  IP del usuario
     * @param  string  $action  Acción realizada
     * @param  array  $context  Contexto adicional
     * @return array  ['is_suspicious' => bool, 'reasons' => array, 'risk_score' => int]
     */
    public static function detectSuspiciousBehavior(string $ip, string $action, array $context = []): array;

    /**
     * Generar token seguro
     *
     * @param  int  $length  Longitud del token
     * @return string  Token generado
     */
    public static function generateSecureToken(int $length = 64): string;

    /**
     * Generar token para reset de contraseña
     *
     * @return string  Token único
     */
    public static function generatePasswordResetToken(): string;

    /**
     * Validar token de reset de contraseña
     *
     * @param  string  $token  Token a validar
     * @param  string  $storedToken  Token almacenado
     * @return bool  True si los tokens coinciden
     */
    public static function validatePasswordResetToken(string $token, string $storedToken): bool;

    /**
     * Sanitizar entrada HTML
     *
     * @param  string  $input  Entrada a sanitizar
     * @return string  Entrada sanitizada
     */
    public static function sanitizeHtml(string $input): string;

    /**
     * Validar CSRF token
     *
     * @param  string  $token  Token a validar
     * @return bool  True si el token es válido
     */
    public static function validateCsrfToken(string $token): bool;

    /**
     * Registrar acción fallida
     *
     * @param  string  $ip  IP del usuario
     * @param  string  $action  Acción realizada
     * @return void
     */
    public static function recordFailedAction(string $ip, string $action): void;
}