<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * AuthServiceInterface
 *
 * Contrato para el servicio de autenticación.
 */
interface AuthServiceInterface
{
    /**
     * Autenticar usuario con credenciales
     *
     * @param  array  $credentials  Credenciales ['identity_document' => string, 'password' => string]
     * @param  string|null  $ipAddress  Dirección IP del cliente
     * @return array ['user' => User, 'tokens' => array] o null si falla
     */
    public function authenticate(array $credentials, ?string $ipAddress = null): ?array;

    /**
     * Generar tokens para un usuario
     *
     * @param  User  $user  Usuario para el cual generar tokens
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return array ['access_token' => string, 'refresh_token' => string]
     */
    public function generateTokens(User $user, array $customClaims = []): array;

    /**
     * Renovar access token usando refresh token
     *
     * @param  string|null  $refreshToken  Refresh token a usar
     * @return array ['access_token' => string, 'refresh_token' => string, 'user' => User]
     */
    public function refreshToken(?string $refreshToken = null): array;

    /**
     * Obtener usuario desde refresh token
     *
     * @param  string  $refreshToken  Refresh token
     * @return User|null Usuario o null si el token es inválido
     */
    public function getUserFromRefreshToken(string $refreshToken): ?User;

    /**
     * Revocar token (logout)
     *
     * @param  string|null  $token  Token a revocar
     * @return bool True si se revocó exitosamente o ya estaba revocado
     */
    public function revokeToken(?string $token = null): bool;

    /**
     * Verificar si un identificador/IP está bloqueado por intentos fallidos
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     * @return bool True si está bloqueado
     */
    public function isLocked(string $identifier, ?string $ipAddress = null): bool;

    /**
     * Obtener número de intentos fallidos restantes
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     * @return int Número de intentos restantes antes del bloqueo
     */
    public function getRemainingAttempts(string $identifier, ?string $ipAddress = null): int;

    /**
     * Obtener tiempo de expiración del access token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getTokenExpiration(): int;

    /**
     * Obtener tiempo de expiración del refresh token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getRefreshTokenExpiration(): int;
}