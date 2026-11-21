<?php

namespace App\Services;

use App\Contracts\TokenServiceInterface;
use App\Models\Blacklist;
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
class TokenService implements TokenServiceInterface
{
    public function __construct(
        private JWTAuthService $jwtAuth
    ) {}

    /**
     * Generar access token para un usuario
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @param  array<string, mixed>  $customClaims  Claims personalizados adicionales
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
            // `type` va al final: nadie puede pasar un claim que lo sobrescriba.
            $claims = array_merge($userClaims, $customClaims, ['type' => 'access']);

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
     * @param  array<string, mixed>  $customClaims  Claims adicionales (`sid`, `jti`); `type` y `exp` no se pueden pisar
     * @return string Refresh token JWT
     *
     * @throws JWTException
     */
    public function generateRefreshToken(User $user, array $customClaims = []): string
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
                $token = $this->jwtAuth->customClaims(array_merge($customClaims, [
                    'type' => 'refresh',
                    'exp' => $expirationTime, // Expiración explícita en segundos Unix timestamp
                ]))->fromUser($user);
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
     * @param  array<string, mixed>  $customClaims  Claims personalizados adicionales
     * @return array<string, mixed> ['access_token' => string, 'refresh_token' => string]
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
     * Tokens de una sesión: ambos llevan `sid`; el refresh lleva además el `jti` que la sesión
     * tiene anotado como vigente (la rotación lo compara).
     *
     * @return array{access_token: string, refresh_token: string}
     *
     * @throws JWTException
     */
    public function generateSessionTokens(User $user, string $sid, string $refreshJti): array
    {
        return [
            'access_token' => $this->generateAccessToken($user, ['sid' => $sid]),
            'refresh_token' => $this->generateRefreshToken($user, ['sid' => $sid, 'jti' => $refreshJti]),
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
        } catch (TokenBlacklistedException $e) {
            LogService::warning('Token en blacklist', [
                'error' => $e->getMessage(),
            ], 'auth');

            return false;
        } catch (TokenInvalidException $e) {
            LogService::warning('Token inválido', [
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
     * @return array<string, mixed> Claims del token
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
     * Revocar un refresh token concreto (logout de esa sesión).
     *
     * Solo se revoca si es de refresco y pertenece al usuario indicado: un
     * usuario no puede invalidar tokens ajenos.
     *
     * @return bool True si se revocó
     */
    public function revokeRefreshToken(string $refreshToken, User $user): bool
    {
        try {
            $claims = $this->jwtAuth->setToken($refreshToken)->getPayload()->toArray();

            if (($claims['type'] ?? null) !== 'refresh' || (string) ($claims['sub'] ?? '') !== (string) $user->getKey()) {
                return false;
            }

            $this->jwtAuth->invalidate(true);

            return true;
        } catch (JWTException $e) {
            // Caducado, ya revocado o malformado: no hay nada que revocar
            return false;
        } finally {
            $this->jwtAuth->unsetToken();
        }
    }

    /**
     * Huella corta (SHA-256) para correlacionar un fallo en los logs sin escribir ningún trozo del
     * token: un fragmento del JWT (cabecera + inicio del payload) sí identifica y ayuda a falsificar.
     */
    private static function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    /**
     * Valida un refresh token SIN consumirlo: tipo, firma, caducidad, usuario, versión de
     * tokens y lista negra. Devuelve sus claims y el usuario.
     *
     * @return array{claims: array<string, mixed>, user: User}
     *
     * @throws JWTException
     */
    public function validateRefreshToken(?string $refreshToken): array
    {
        try {
            if (! $refreshToken) {
                throw new TokenInvalidException('Refresh token no proporcionado');
            }

            // parseToken() lee el token del request: se pone temporalmente en Authorization.
            $request = request();
            $originalAuthHeader = $request->header('Authorization');

            try {
                $request->headers->set('Authorization', 'Bearer '.$refreshToken);

                $claims = $this->jwtAuth->parseToken()->getPayload()->toArray();
            } catch (TokenExpiredException $e) {
                LogService::warning('Refresh token expirado al parsear', [
                    'error' => $e->getMessage(),
                    'token_fingerprint' => self::fingerprint($refreshToken),
                ], 'auth');

                throw $e;
            } catch (TokenInvalidException $e) {
                LogService::error('Refresh token inválido al parsear', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'token_fingerprint' => self::fingerprint($refreshToken),
                ], 'auth');

                throw $e;
            } catch (JWTException $e) {
                LogService::error('Error al parsear refresh token', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'token_fingerprint' => self::fingerprint($refreshToken),
                ], 'auth');

                throw new TokenInvalidException('Token inválido: '.$e->getMessage());
            } finally {
                if ($originalAuthHeader) {
                    $request->headers->set('Authorization', $originalAuthHeader);
                } else {
                    $request->headers->remove('Authorization');
                }
            }

            if (($claims['type'] ?? null) !== 'refresh') {
                throw new TokenInvalidException('Token proporcionado no es un refresh token');
            }

            $userId = $claims['sub'] ?? null;
            if (! $userId) {
                throw new TokenInvalidException('Usuario no encontrado en el token');
            }

            $authenticatedUser = User::find($userId);
            if (! $authenticatedUser instanceof User) {
                throw new TokenInvalidException('Usuario no encontrado');
            }

            // Sesión revocada (cambio/reset de contraseña): versión de tokens distinta
            if ((int) ($claims['tv'] ?? 0) !== (int) $authenticatedUser->token_version) {
                throw new TokenInvalidException('Sesión revocada');
            }

            // Identidad bloqueada por un administrador
            if (Blacklist::isBlocked(
                email: $authenticatedUser->email,
                identityDocument: $authenticatedUser->identity_document,
                userId: $authenticatedUser->id
            )) {
                LogService::warning('Refresh denegado: usuario en lista negra', [
                    'user_id' => $authenticatedUser->id,
                ], 'security');

                throw new TokenInvalidException('Sesión no permitida');
            }

            return ['claims' => $claims, 'user' => $authenticatedUser];
        } catch (TokenExpiredException $e) {
            LogService::warning('Refresh token expirado', ['error' => $e->getMessage()], 'auth');

            throw $e;
        } catch (TokenInvalidException $e) {
            LogService::warning('Refresh token inválido', ['error' => $e->getMessage()], 'auth');

            throw $e;
        }
    }

    /**
     * Renovar access token usando refresh token (con rotación por lista negra, SIN sesión).
     *
     * Es el camino de los tokens anteriores a las sesiones; el login actual rota con
     * `AuthService::refreshToken()` (sesión + detección de reutilización).
     *
     * @param  string|null  $refreshToken  Refresh token a usar
     * @return array<string, mixed> ['access_token' => string, 'refresh_token' => string, 'user' => User]
     *
     * @throws JWTException
     */
    public function refreshToken(?string $refreshToken = null): array
    {
        try {
            $validated = $this->validateRefreshToken($refreshToken);
            $authenticatedUser = $validated['user'];

            // Revocar el refresh token antiguo (rotación) DESPUÉS de validar
            $this->jwtAuth->setToken((string) $refreshToken)->invalidate(true);

            $newTokens = $this->generateTokens($authenticatedUser);

            LogService::info('Token renovado con rotación', [
                'user_id' => $authenticatedUser->id,
            ], 'auth');

            return array_merge($newTokens, ['user' => $authenticatedUser]);
        } catch (TokenExpiredException|TokenInvalidException $e) {
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
