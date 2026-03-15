<?php

namespace App\Services;

use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

/**
 * AuthService
 *
 * Servicio para lógica de autenticación:
 * - Autenticación de credenciales
 * - Generación de tokens
 * - Renovación de tokens
 * - Revocación de tokens
 * - Manejo de intentos fallidos
 */
class AuthService
{
    protected TokenService $tokenService;

    /**
     * Número máximo de intentos fallidos antes de bloquear
     */
    protected int $maxAttempts = 5;

    /**
     * Tiempo de bloqueo en minutos después de alcanzar maxAttempts
     */
    protected int $lockoutTime = 15;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Autenticar usuario con credenciales
     *
     * @param  array  $credentials  Credenciales ['identity_document' => string, 'password' => string]
     * @param  string|null  $ipAddress  Dirección IP del cliente
     * @return array ['user' => User, 'tokens' => array] o null si falla
     *
     * @throws \Exception
     */
    public function authenticate(array $credentials, ?string $ipAddress = null): ?array
    {
        $identityDocument = $credentials['identity_document'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $identityDocument || ! $password) {
            throw new \InvalidArgumentException('Número de identidad y contraseña son requeridos');
        }

        // Verificar si el usuario está bloqueado por intentos fallidos
        if ($this->isLocked($identityDocument, $ipAddress)) {
            LogService::warning('Intento de login bloqueado por intentos fallidos', [
                'identifier' => $identityDocument,
                'ip' => $ipAddress,
            ], 'security');

            throw new \Exception('Demasiados intentos fallidos. Intenta nuevamente en '.$this->lockoutTime.' minutos.');
        }

        // Buscar usuario por identity_document
        $user = User::where('identity_document', $identityDocument)->first();

        if (! $user) {
            $this->recordFailedAttempt($identityDocument, $ipAddress);
            LogService::warning('Intento de login fallido - Usuario no encontrado', [
                'identifier' => $identityDocument,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Verificar contraseña
        if (! Hash::check($password, $user->password)) {
            $this->recordFailedAttempt($identityDocument, $ipAddress);
            LogService::warning('Intento de login fallido - Contraseña incorrecta', [
                'user_id' => $user->id,
                'identifier' => $identityDocument,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Rehash automático si la contraseña usa un algoritmo diferente o necesita actualización
        // Esto permite migrar de bcrypt a argon2id automáticamente
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($password);
            $user->save();

            LogService::info('Contraseña rehasheada automáticamente durante login', [
                'user_id' => $user->id,
                'identifier' => $identityDocument,
            ], 'security');
        }

        // Limpiar intentos fallidos al autenticar exitosamente
        $this->clearFailedAttempts($identityDocument, $ipAddress);

        // Generar tokens
        try {
            $tokens = $this->tokenService->generateTokens($user);

            LogService::info('Autenticación exitosa', [
                'user_id' => $user->id,
                'identifier' => $identityDocument,
                'ip' => $ipAddress,
            ], 'security');

            // Disparar evento UserLoggedIn
            event(new UserLoggedIn(
                $user,
                $ipAddress,
                request()->userAgent()
            ));

            return [
                'user' => $user,
                'tokens' => $tokens,
            ];
        } catch (JWTException $e) {
            LogService::error('Error al generar tokens en autenticación', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], 'auth');

            throw new \Exception('Error al generar tokens de autenticación', 0, $e);
        }
    }

    /**
     * Generar tokens para un usuario
     *
     * @param  User  $user  Usuario para el cual generar tokens
     * @param  array  $customClaims  Claims personalizados adicionales
     * @return array ['access_token' => string, 'refresh_token' => string]
     *
     * @throws JWTException
     */
    public function generateTokens(User $user, array $customClaims = []): array
    {
        return $this->tokenService->generateTokens($user, $customClaims);
    }

    /**
     * Renovar access token usando refresh token
     *
     * @param  string|null  $refreshToken  Refresh token a usar
     * @return array ['access_token' => string, 'refresh_token' => string, 'user' => User]
     *
     * @throws JWTException
     */
    public function refreshToken(?string $refreshToken = null): array
    {
        try {
            $result = $this->tokenService->refreshToken($refreshToken);

            if (isset($result['user'])) {
                LogService::info('Token renovado exitosamente', [
                    'user_id' => $result['user']->id,
                ], 'auth');
            }

            return $result;
        } catch (JWTException $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Obtener usuario desde refresh token
     *
     * @param  string  $refreshToken  Refresh token
     * @return User|null Usuario o null si el token es inválido
     */
    public function getUserFromRefreshToken(string $refreshToken): ?User
    {
        return $this->tokenService->getUserFromToken($refreshToken);
    }

    /**
     * Revocar token (logout)
     *
     * @param  string|null  $token  Token a revocar
     * @return bool True si se revocó exitosamente o ya estaba revocado
     *
     * @throws JWTException
     */
    public function revokeToken(?string $token = null): bool
    {
        try {
            // Intentar obtener usuario antes de revocar (para logging y eventos)
            $user = $this->tokenService->getUserFromToken($token);

            // Revocar token (maneja automáticamente el caso de token ya blacklisted)
            $result = $this->tokenService->revokeToken($token);

            if ($result) {
                if ($user) {
                    LogService::info('Token revocado exitosamente', [
                        'user_id' => $user->id,
                    ], 'security');

                    // Disparar evento UserLoggedOut solo si tenemos usuario
                    event(new UserLoggedOut(
                        $user,
                        request()->ip()
                    ));
                } else {
                    LogService::info('Token ya estaba revocado (blacklisted)', [], 'security');
                }
            }

            return $result;
        } catch (JWTException $e) {
            // Si el error es que el token está blacklisted, consideramos el logout exitoso
            if (str_contains($e->getMessage(), 'blacklisted')) {
                LogService::info('Token ya estaba revocado (blacklisted)', [
                    'error' => $e->getMessage(),
                ], 'security');

                return true;
            }

            LogService::error('Error al revocar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Verificar si un identificador/IP está bloqueado por intentos fallidos
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     * @return bool True si está bloqueado
     */
    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        $key = $this->getLockoutKey($identifier, $ipAddress);

        return Cache::has($key);
    }

    /**
     * Registrar intento fallido
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     */
    protected function recordFailedAttempt(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->getAttemptsKey($identifier, $ipAddress);
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes($this->lockoutTime));

        // Si alcanzó el máximo de intentos, bloquear
        if ($attempts >= $this->maxAttempts) {
            $lockoutKey = $this->getLockoutKey($identifier, $ipAddress);
            Cache::put($lockoutKey, true, now()->addMinutes($this->lockoutTime));

            LogService::warning('Usuario bloqueado por intentos fallidos', [
                'identifier' => $identifier,
                'ip' => $ipAddress,
                'attempts' => $attempts,
                'lockout_minutes' => $this->lockoutTime,
            ], 'security');
        }
    }

    /**
     * Limpiar intentos fallidos después de autenticación exitosa
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     */
    protected function clearFailedAttempts(string $identifier, ?string $ipAddress = null): void
    {
        $attemptsKey = $this->getAttemptsKey($identifier, $ipAddress);
        $lockoutKey = $this->getLockoutKey($identifier, $ipAddress);

        Cache::forget($attemptsKey);
        Cache::forget($lockoutKey);
    }

    /**
     * Obtener clave de caché para intentos fallidos
     */
    protected function getAttemptsKey(string $identifier, ?string $ipAddress = null): string
    {
        $key = $ipAddress ? "{$identifier}:{$ipAddress}" : $identifier;

        return 'auth:failed_attempts:'.md5($key);
    }

    /**
     * Obtener clave de caché para bloqueo
     */
    protected function getLockoutKey(string $identifier, ?string $ipAddress = null): string
    {
        $key = $ipAddress ? "{$identifier}:{$ipAddress}" : $identifier;

        return 'auth:lockout:'.md5($key);
    }

    /**
     * Obtener número de intentos fallidos restantes
     *
     * @param  string  $identifier  Número de identidad del usuario
     * @param  string|null  $ipAddress  Dirección IP
     * @return int Número de intentos restantes antes del bloqueo
     */
    public function getRemainingAttempts(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->getAttemptsKey($identifier, $ipAddress);
        $attempts = Cache::get($key, 0);

        return max(0, $this->maxAttempts - $attempts);
    }

    /**
     * Obtener tiempo de expiración del access token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getTokenExpiration(): int
    {
        return $this->tokenService->getTokenExpiration();
    }

    /**
     * Obtener tiempo de expiración del refresh token en segundos
     *
     * @return int Tiempo de expiración en segundos
     */
    public function getRefreshTokenExpiration(): int
    {
        return $this->tokenService->getRefreshTokenExpiration();
    }
}
