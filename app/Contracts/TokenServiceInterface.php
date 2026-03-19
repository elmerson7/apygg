<?php

namespace App\Contracts;

use App\Models\User;

/**
 * TokenServiceInterface
 *
 * Contrato para el servicio de gestión de tokens JWT.
 */
interface TokenServiceInterface
{
    /**
     * Generar access token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return string Token JWT
     */
    public function generateAccessToken(User $user, array $customClaims = []): string;

    /**
     * Generar refresh token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @return string Refresh token JWT
     */
    public function generateRefreshToken(User $user): string;

    /**
     * Generar access token y refresh token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar los tokens
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return array ['access_token' => string, 'refresh_token' => string]
     */
    public function generateTokens(User $user, array $customClaims = []): array;

    /**
     * Validar token JWT
     *
     * @param  string|null  $token  Token a validar (null = usar token del request)
     * @return bool True si el token es válido
     */
    public function validateToken(?string $token = null): bool;

    /**
     * Obtener usuario desde token
     *
     * @param  string|null  $token  Token a usar (null = usar token del request)
     * @return User|null Usuario autenticado o null si el token es inválido
     */
    public function getUserFromToken(?string $token = null): ?User;

    /**
     * Obtener claims del token
     *
     * @param  string|null  $token  Token a usar (null = usar token del request)
     * @return array Claims del token
     *
     * @throws \Exception
     */
    public function getTokenClaims(?string $token = null): array;

    /**
     * Revocar token (agregar a blacklist)
     *
     * @param  string|null  $token  Token a revocar (null = usar token del request)
     * @return bool True si se revocó exitosamente o ya estaba revocado
     *
     * @throws \Exception
     */
    public function revokeToken(?string $token = null): bool;

    /**
     * Renovar access token usando refresh token (con rotación)
     *
     * @param  string|null  $refreshToken  Refresh token a usar (null = usar token del request)
     * @return array ['access_token' => string, 'refresh_token' => string, 'user' => User]
     *
     * @throws \Exception
     */
    public function refreshToken(?string $refreshToken = null): array;

    /**
     * Obtener tiempo de expiración del token en segundos
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