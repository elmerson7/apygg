<?php

namespace App\Services;

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * TokenService
 *
 * Servicio para gestión de tokens JWT:
 * - Generación de access tokens y refresh tokens
 * - Validación de tokens
 * - Revocación de tokens
 * - Renovación con rotación
 */
class TokenService
{
    /**
     * Generar access token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return string Token JWT
     *
     * @throws JWTException
     */
    public function generateAccessToken(User $user, array $customClaims = []): string
    {
        try {
            // Obtener claims personalizados del usuario
            $userClaims = $user->getJWTCustomClaims();

            // Combinar claims del usuario con claims adicionales
            $claims = array_merge($userClaims, $customClaims);

            // Generar token con claims
            $token = JWTAuth::customClaims($claims)->fromUser($user);

            LogService::debug('Access token generado', [
                'user_id' => $user->id,
                'token_ttl' => config('jwt.ttl'),
            ], 'auth');

            return $token;
        } catch (JWTException $e) {
            LogService::error('Error al generar access token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Generar refresh token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @return string Refresh token JWT
     *
     * @throws JWTException
     */
    public function generateRefreshToken(User $user): string
    {
        try {
            // Refresh token con expiración más larga
            $refreshTtl = config('jwt.refresh_ttl', 20160); // Por defecto 14 días

            $token = JWTAuth::setTTL($refreshTtl)->customClaims([
                'type' => 'refresh',
            ])->fromUser($user);

            LogService::debug('Refresh token generado', [
                'user_id' => $user->id,
                'token_ttl' => $refreshTtl,
            ], 'auth');

            return $token;
        } catch (JWTException $e) {
            LogService::error('Error al generar refresh token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Generar access token y refresh token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar los tokens
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return array ['access_token' => string, 'refresh_token' => string]
     *
     * @throws JWTException
     */
    public function generateTokens(User $user, array $customClaims = []): array
    {
        return [
            'access_token' => $this->generateAccessToken($user, $customClaims),
            'refresh_token' => $this->generateRefreshToken($user),
        ];
    }

    /**
     * Validar token JWT
     *
     * @param  string|null  $token  Token a validar (null = usar token del request)
     * @return bool True si el token es válido
     */
    public function validateToken(?string $token = null): bool
    {
        try {
            if ($token) {
                JWTAuth::setToken($token);
            }

            JWTAuth::parseToken()->authenticate();

            return true;
        } catch (TokenExpiredException $e) {
            LogService::warning('Token expirado', [
                'error' => $e->getMessage(),
            ], 'auth');

            return false;
        } catch (TokenInvalidException $e) {
            LogService::warning('Token inválido', [
                'error' => $e->getMessage(),
            ], 'auth');

            return false;
        } catch (TokenBlacklistedException $e) {
            LogService::warning('Token en blacklist', [
                'error' => $e->getMessage(),
            ], 'auth');

            return false;
        } catch (JWTException $e) {
            LogService::warning('Error al validar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            return false;
        }
    }

    /**
     * Obtener usuario desde token
     *
     * @param  string|null  $token  Token a usar (null = usar token del request)
     * @return User|null Usuario autenticado o null si el token es inválido
     */
    public function getUserFromToken(?string $token = null): ?User
    {
        try {
            if ($token) {
                JWTAuth::setToken($token);
            }

            return JWTAuth::parseToken()->authenticate();
        } catch (JWTException $e) {
            return null;
        }
    }

    /**
     * Obtener claims del token
     *
     * @param  string|null  $token  Token a usar (null = usar token del request)
     * @return array Claims del token
     *
     * @throws JWTException
     */
    public function getTokenClaims(?string $token = null): array
    {
        try {
            if ($token) {
                JWTAuth::setToken($token);
            }

            return JWTAuth::parseToken()->getPayload()->toArray();
        } catch (JWTException $e) {
            LogService::error('Error al obtener claims del token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Revocar token (agregar a blacklist)
     *
     * @param  string|null  $token  Token a revocar (null = usar token del request)
     * @return bool True si se revocó exitosamente
     *
     * @throws JWTException
     */
    public function revokeToken(?string $token = null): bool
    {
        try {
            if ($token) {
                JWTAuth::setToken($token);
            }

            $token = JWTAuth::getToken();

            if (! $token) {
                return false;
            }

            // Invalidar token (agregar a blacklist)
            JWTAuth::invalidate($token);

            // Obtener claims para logging
            $claims = JWTAuth::parseToken()->getPayload()->toArray();
            $userId = $claims['sub'] ?? null;

            LogService::info('Token revocado', [
                'user_id' => $userId,
                'jti' => $claims['jti'] ?? null,
            ], 'auth');

            return true;
        } catch (JWTException $e) {
            LogService::error('Error al revocar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Renovar access token usando refresh token (con rotación)
     *
     * @param  string|null  $refreshToken  Refresh token a usar (null = usar token del request)
     * @return array ['access_token' => string, 'refresh_token' => string]
     *
     * @throws JWTException
     */
    public function refreshToken(?string $refreshToken = null): array
    {
        try {
            if ($refreshToken) {
                JWTAuth::setToken($refreshToken);
            }

            $token = JWTAuth::getToken();

            if (! $token) {
                throw new TokenInvalidException('Token no proporcionado');
            }

            // Verificar que es un refresh token
            $claims = JWTAuth::parseToken()->getPayload()->toArray();
            if (($claims['type'] ?? null) !== 'refresh') {
                throw new TokenInvalidException('Token proporcionado no es un refresh token');
            }

            // Obtener usuario del token
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                throw new TokenInvalidException('Usuario no encontrado en el token');
            }

            // Revocar el refresh token antiguo (rotación)
            JWTAuth::invalidate($token);

            // Generar nuevos tokens
            $newTokens = $this->generateTokens($user);

            LogService::info('Token renovado con rotación', [
                'user_id' => $user->id,
            ], 'auth');

            return $newTokens;
        } catch (TokenExpiredException $e) {
            LogService::warning('Refresh token expirado', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        } catch (TokenInvalidException $e) {
            LogService::warning('Refresh token inválido', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        } catch (JWTException $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Obtener tiempo de expiración del token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getTokenExpiration(): int
    {
        return config('jwt.ttl', 60) * 60; // Convertir minutos a segundos
    }

    /**
     * Obtener tiempo de expiración del refresh token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getRefreshTokenExpiration(): int
    {
        return config('jwt.refresh_ttl', 20160) * 60; // Convertir minutos a segundos
    }
}
