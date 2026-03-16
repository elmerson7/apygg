<?php

namespace App\Services;

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth as JWTAuthService;

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
    public function __construct(
        private JWTAuthService $jwtAuth
    ) {}

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
            $token = $this->jwtAuth->customClaims($claims)->fromUser($user);

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
            $refreshTtl = config('jwt.refresh_ttl', 20160); // Por defecto 14 días (en minutos)

            // Guardar TTL original
            $originalTtl = config('jwt.ttl');

            // Calcular expiración explícitamente (en segundos desde ahora)
            $expirationTime = now()->addMinutes($refreshTtl)->timestamp;

            // Configurar TTL temporalmente para el refresh token
            config(['jwt.ttl' => $refreshTtl]);

            try {
                // Generar token con expiración explícita y claim de tipo refresh
                $token = $this->jwtAuth->customClaims([
                    'type' => 'refresh',
                    'exp' => $expirationTime, // Expiración explícita en segundos Unix timestamp
                ])->fromUser($user);
            } finally {
                // Restaurar TTL original
                config(['jwt.ttl' => $originalTtl]);
            }

            LogService::debug('Refresh token generado', [
                'user_id' => $user->id,
                'token_ttl' => $refreshTtl,
                'expires_at' => date('Y-m-d H:i:s', $expirationTime),
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
                $this->jwtAuth->setToken($token);
            }

            $this->jwtAuth->parseToken()->authenticate();

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
                $this->jwtAuth->setToken($token);
            }

            $user = $this->jwtAuth->parseToken()->authenticate();

            return $user instanceof User ? $user : null;
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
                $this->jwtAuth->setToken($token);
            }

            return $this->jwtAuth->parseToken()->getPayload()->toArray();
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
     * @return bool True si se revocó exitosamente o ya estaba revocado
     *
     * @throws JWTException
     */
    public function revokeToken(?string $token = null): bool
    {
        try {
            if ($token) {
                $this->jwtAuth->setToken($token);
            }

            $token = $this->jwtAuth->getToken();

            if (! $token) {
                return false;
            }

            // Intentar obtener claims antes de invalidar (para logging)
            $claims = null;
            $userId = null;

            try {
                $claims = $this->jwtAuth->parseToken()->getPayload()->toArray();
                $userId = $claims['sub'] ?? null;
            } catch (TokenBlacklistedException $e) {
                // Si el token ya está blacklisted, consideramos el logout exitoso
                LogService::info('Token ya estaba revocado (blacklisted)', [
                    'error' => $e->getMessage(),
                ], 'auth');

                return true;
            } catch (JWTException $e) {
                // Si hay otro error al obtener claims, continuamos con la invalidación
                LogService::warning('No se pudieron obtener claims del token antes de revocar', [
                    'error' => $e->getMessage(),
                ], 'auth');
            }

            // Invalidar token (agregar a blacklist)
            try {
                $this->jwtAuth->invalidate(true);
            } catch (TokenBlacklistedException $e) {
                // Si el token ya está blacklisted, consideramos el logout exitoso
                LogService::info('Token ya estaba revocado (blacklisted)', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ], 'auth');

                return true;
            }

            LogService::info('Token revocado exitosamente', [
                'user_id' => $userId,
                'jti' => $claims['jti'] ?? null,
            ], 'auth');

            return true;
        } catch (TokenBlacklistedException $e) {
            // Si el token ya está blacklisted, consideramos el logout exitoso
            LogService::info('Token ya estaba revocado (blacklisted)', [
                'error' => $e->getMessage(),
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
     * @return array ['access_token' => string, 'refresh_token' => string, 'user' => User]
     *
     * @throws JWTException
     */
    public function refreshToken(?string $refreshToken = null): array
    {
        try {
            if (! $refreshToken) {
                throw new TokenInvalidException('Refresh token no proporcionado');
            }

            // IMPORTANTE: parseToken() intenta obtener el token del request automáticamente
            // Solución: Modificar temporalmente el request para incluir el token en Authorization header
            // antes de llamar a parseToken()
            $request = request();
            $originalAuthHeader = $request->header('Authorization');

            try {
                // Agregar el refresh token al header Authorization temporalmente
                $request->headers->set('Authorization', 'Bearer '.$refreshToken);

                // Ahora parseToken() podrá obtener el token del request
                $claims = $this->jwtAuth->parseToken()->getPayload()->toArray();
            } catch (TokenExpiredException $e) {
                LogService::warning('Refresh token expirado al parsear', [
                    'error' => $e->getMessage(),
                    'token_preview' => substr($refreshToken, 0, 50).'...',
                ], 'auth');

                throw $e;
            } catch (TokenInvalidException $e) {
                LogService::error('Refresh token inválido al parsear', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'token_preview' => substr($refreshToken, 0, 50).'...',
                ], 'auth');

                throw $e;
            } catch (JWTException $e) {
                LogService::error('Error al parsear refresh token', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'token_preview' => substr($refreshToken, 0, 50).'...',
                ], 'auth');

                throw new TokenInvalidException('Token inválido: '.$e->getMessage());
            } finally {
                // Restaurar el header Authorization original
                if ($originalAuthHeader) {
                    $request->headers->set('Authorization', $originalAuthHeader);
                } else {
                    $request->headers->remove('Authorization');
                }
            }

            // Verificar que es un refresh token
            if (($claims['type'] ?? null) !== 'refresh') {
                throw new TokenInvalidException('Token proporcionado no es un refresh token');
            }

            // Obtener usuario del token ANTES de invalidarlo
            // Obtener el ID del usuario desde el claim 'sub'
            $userId = $claims['sub'] ?? null;
            if (! $userId) {
                throw new TokenInvalidException('Usuario no encontrado en el token');
            }

            // Buscar el usuario en la base de datos
            $authenticatedUser = User::find($userId);
            if (! $authenticatedUser instanceof User) {
                throw new TokenInvalidException('Usuario no encontrado');
            }

            // Revocar el refresh token antiguo (rotación)
            // IMPORTANTE: invalidar después de obtener el usuario
            // Configurar el token antes de invalidarlo
            $this->jwtAuth->setToken($refreshToken)->invalidate(true);

            // Generar nuevos tokens
            $newTokens = $this->generateTokens($authenticatedUser);

            LogService::info('Token renovado con rotación', [
                'user_id' => $authenticatedUser->id,
            ], 'auth');

            // Retornar tokens junto con el usuario
            return array_merge($newTokens, ['user' => $authenticatedUser]);
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
