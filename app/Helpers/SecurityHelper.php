<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SecurityHelper
 * 
 * Helper estático para operaciones de seguridad simples:
 * generación de tokens, validación de contraseñas, sanitización HTML.
 * 
 * Para operaciones más complejas, usar SecurityService.
 * 
 * @package App\Helpers
 */
class SecurityHelper
{
    /**
     * Generar token seguro aleatorio
     *
     * @param int $length Longitud del token en bytes (default: 32)
     * @param bool $hex Si retornar en hexadecimal (default: true)
     * @return string Token generado
     */
    public static function generateToken(int $length = 32, bool $hex = true): string
    {
        $bytes = random_bytes($length);
        
        return $hex ? bin2hex($bytes) : base64_encode($bytes);
    }

    /**
     * Generar token seguro con prefijo
     *
     * @param string $prefix Prefijo del token
     * @param int $length Longitud del token en bytes (default: 32)
     * @return string Token con prefijo
     */
    public static function generateTokenWithPrefix(string $prefix, int $length = 32): string
    {
        return $prefix . '_' . self::generateToken($length);
    }

    /**
     * Generar token alfanumérico seguro
     *
     * @param int $length Longitud del token (default: 64)
     * @return string Token alfanumérico
     */
    public static function generateAlphanumericToken(int $length = 64): string
    {
        return Str::random($length);
    }

    /**
     * Validar fortaleza de contraseña
     *
     * @param string $password Contraseña a validar
     * @param int $minLength Longitud mínima (default: 8)
     * @param bool $requireUppercase Si requiere mayúsculas (default: true)
     * @param bool $requireLowercase Si requiere minúsculas (default: true)
     * @param bool $requireNumbers Si requiere números (default: true)
     * @param bool $requireSymbols Si requiere símbolos (default: true)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validatePasswordStrength(
        string $password,
        int $minLength = 8,
        bool $requireUppercase = true,
        bool $requireLowercase = true,
        bool $requireNumbers = true,
        bool $requireSymbols = true
    ): array {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "La contraseña debe tener al menos {$minLength} caracteres.";
        }

        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos una letra mayúscula.';
        }

        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos una letra minúscula.';
        }

        if ($requireNumbers && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos un número.';
        }

        if ($requireSymbols && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos un símbolo especial.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Verificar contraseña contra hash
     *
     * @param string $password Contraseña en texto plano
     * @param string $hash Hash de la contraseña
     * @return bool true si la contraseña coincide
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }

    /**
     * Hash de contraseña
     *
     * @param string $password Contraseña en texto plano
     * @return string Hash de la contraseña
     */
    public static function hashPassword(string $password): string
    {
        return Hash::make($password);
    }

    /**
     * Sanitizar HTML eliminando tags peligrosos
     *
     * @param string $html HTML a sanitizar
     * @param string|null $allowedTags Tags permitidos (opcional, ej: '<p><br><strong>')
     * @return string HTML sanitizado
     */
    public static function sanitizeHtml(string $html, ?string $allowedTags = null): string
    {
        if ($allowedTags !== null) {
            return strip_tags($html, $allowedTags);
        }

        return strip_tags($html);
    }

    /**
     * Escapar HTML para prevenir XSS
     *
     * @param string $string String a escapar
     * @param int $flags Flags para htmlspecialchars (default: ENT_QUOTES | ENT_HTML5)
     * @return string String escapado
     */
    public static function escapeHtml(string $string, int $flags = ENT_QUOTES | ENT_HTML5): string
    {
        return htmlspecialchars($string, $flags, 'UTF-8');
    }

    /**
     * Validar URL contra whitelist
     *
     * @param string $url URL a validar
     * @param array $whitelist Lista de dominios permitidos
     * @return bool true si la URL está en la whitelist
     */
    public static function isUrlWhitelisted(string $url, array $whitelist): bool
    {
        $parsedUrl = parse_url($url);
        
        if (!isset($parsedUrl['host'])) {
            return false;
        }

        $host = $parsedUrl['host'];

        foreach ($whitelist as $allowed) {
            // Permitir coincidencia exacta o subdominios
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generar token CSRF
     *
     * @return string Token CSRF
     */
    public static function generateCsrfToken(): string
    {
        return Str::random(40);
    }

    /**
     * Enmascarar datos sensibles para logging
     *
     * @param string $data Datos a enmascarar
     * @param int $visibleChars Caracteres visibles al inicio (default: 4)
     * @param string $mask Carácter de enmascaramiento (default: '*')
     * @return string Datos enmascarados
     */
    public static function maskSensitiveData(string $data, int $visibleChars = 4, string $mask = '*'): string
    {
        $length = strlen($data);

        if ($length <= $visibleChars) {
            return str_repeat($mask, $length);
        }

        $visible = substr($data, 0, $visibleChars);
        $masked = str_repeat($mask, $length - $visibleChars);

        return $visible . $masked;
    }

    /**
     * Enmascarar email (ej: j***@example.com)
     *
     * @param string $email Email a enmascarar
     * @param int $visibleChars Caracteres visibles antes del @ (default: 1)
     * @return string Email enmascarado
     */
    public static function maskEmail(string $email, int $visibleChars = 1): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::maskSensitiveData($email, $visibleChars);
        }

        [$localPart, $domain] = explode('@', $email, 2);

        if (strlen($localPart) <= $visibleChars) {
            $maskedLocal = str_repeat('*', strlen($localPart));
        } else {
            $maskedLocal = substr($localPart, 0, $visibleChars) . str_repeat('*', strlen($localPart) - $visibleChars);
        }

        return $maskedLocal . '@' . $domain;
    }

    /**
     * Enmascarar tarjeta de crédito (últimos 4 dígitos visibles)
     *
     * @param string $cardNumber Número de tarjeta
     * @param int $visibleDigits Dígitos visibles al final (default: 4)
     * @return string Número de tarjeta enmascarado
     */
    public static function maskCreditCard(string $cardNumber, int $visibleDigits = 4): string
    {
        $cleaned = preg_replace('/\D/', '', $cardNumber);
        $length = strlen($cleaned);

        if ($length <= $visibleDigits) {
            return str_repeat('*', $length);
        }

        $masked = str_repeat('*', $length - $visibleDigits);
        $visible = substr($cleaned, -$visibleDigits);

        return $masked . $visible;
    }

    /**
     * Validar token CSRF
     *
     * @param string $token Token a validar
     * @param string $sessionToken Token de la sesión
     * @return bool true si el token es válido
     */
    public static function validateCsrfToken(string $token, string $sessionToken): bool
    {
        return hash_equals($sessionToken, $token);
    }

    /**
     * Generar nonce para CSP (Content Security Policy)
     *
     * @param int $length Longitud del nonce (default: 16)
     * @return string Nonce generado
     */
    public static function generateNonce(int $length = 16): string
    {
        return base64_encode(random_bytes($length));
    }
}
