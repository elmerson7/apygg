<?php

namespace App\Services;

use App\Services\LogService;
use App\Services\TokenService;
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
 *
 * @package App\Services
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
     * @param array $credentials Credenciales ['email' => string, 'password' => string]
     * @param string|null $ipAddress Dirección IP del cliente
     * @return array ['user' => User, 'tokens' => array] o null si falla
     * @throws \Exception
     */
    public function authenticate(array $credentials, ?string $ipAddress = null): ?array
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!$email || !$password) {
            throw new \InvalidArgumentException('Email y contraseña son requeridos');
        }

        // Verificar si el usuario está bloqueado por intentos fallidos
        if ($this->isLocked($email, $ipAddress)) {
            LogService::warning('Intento de login bloqueado por intentos fallidos', [
                'email' => $email,
                'ip' => $ipAddress,
            ], 'security');

            throw new \Exception('Demasiados intentos fallidos. Intenta nuevamente en ' . $this->lockoutTime . ' minutos.');
        }

        // Buscar usuario por email
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->recordFailedAttempt($email, $ipAddress);
            LogService::warning('Intento de login fallido - Usuario no encontrado', [
                'email' => $email,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Verificar contraseña
        if (!Hash::check($password, $user->password)) {
            $this->recordFailedAttempt($email, $ipAddress);
            LogService::warning('Intento de login fallido - Contraseña incorrecta', [
                'user_id' => $user->id,
                'email' => $email,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Limpiar intentos fallidos al autenticar exitosamente
        $this->clearFailedAttempts($email, $ipAddress);

        // Generar tokens
        try {
            $tokens = $this->tokenService->generateTokens($user);

            LogService::info('Autenticación exitosa', [
                'user_id' => $user->id,
                'email' => $email,
                'ip' => $ipAddress,
            ], 'security');

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
     * @param User $user Usuario para el cual generar tokens
     * @param array $customClaims Claims personalizados adicionales
     * @return array ['access_token' => string, 'refresh_token' => string]
     * @throws JWTException
     */
    public function generateTokens(User $user, array $customClaims = []): array
    {
        return $this->tokenService->generateTokens($user, $customClaims);
    }

    /**
     * Renovar access token usando refresh token
     *
     * @param string|null $refreshToken Refresh token a usar
     * @return array ['access_token' => string, 'refresh_token' => string]
     * @throws JWTException
     */
    public function refreshToken(?string $refreshToken = null): array
    {
        try {
            $tokens = $this->tokenService->refreshToken($refreshToken);

            // Obtener usuario del token para logging
            $user = $this->tokenService->getUserFromToken($refreshToken);
            if ($user) {
                LogService::info('Token renovado exitosamente', [
                    'user_id' => $user->id,
                ], 'auth');
            }

            return $tokens;
        } catch (JWTException $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Revocar token (logout)
     *
     * @param string|null $token Token a revocar
     * @return bool True si se revocó exitosamente
     * @throws JWTException
     */
    public function revokeToken(?string $token = null): bool
    {
        try {
            $user = $this->tokenService->getUserFromToken($token);
            $result = $this->tokenService->revokeToken($token);

            if ($user && $result) {
                LogService::info('Token revocado exitosamente', [
                    'user_id' => $user->id,
                ], 'security');
            }

            return $result;
        } catch (JWTException $e) {
            LogService::error('Error al revocar token', [
                'error' => $e->getMessage(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * Verificar si un email/IP está bloqueado por intentos fallidos
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return bool True si está bloqueado
     */
    public function isLocked(string $email, ?string $ipAddress = null): bool
    {
        $key = $this->getLockoutKey($email, $ipAddress);
        return Cache::has($key);
    }

    /**
     * Registrar intento fallido
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return void
     */
    protected function recordFailedAttempt(string $email, ?string $ipAddress = null): void
    {
        $key = $this->getAttemptsKey($email, $ipAddress);
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes($this->lockoutTime));

        // Si alcanzó el máximo de intentos, bloquear
        if ($attempts >= $this->maxAttempts) {
            $lockoutKey = $this->getLockoutKey($email, $ipAddress);
            Cache::put($lockoutKey, true, now()->addMinutes($this->lockoutTime));

            LogService::warning('Usuario bloqueado por intentos fallidos', [
                'email' => $email,
                'ip' => $ipAddress,
                'attempts' => $attempts,
                'lockout_minutes' => $this->lockoutTime,
            ], 'security');
        }
    }

    /**
     * Limpiar intentos fallidos después de autenticación exitosa
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return void
     */
    protected function clearFailedAttempts(string $email, ?string $ipAddress = null): void
    {
        $attemptsKey = $this->getAttemptsKey($email, $ipAddress);
        $lockoutKey = $this->getLockoutKey($email, $ipAddress);

        Cache::forget($attemptsKey);
        Cache::forget($lockoutKey);
    }

    /**
     * Obtener clave de caché para intentos fallidos
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return string Clave de caché
     */
    protected function getAttemptsKey(string $email, ?string $ipAddress = null): string
    {
        $identifier = $ipAddress ? "{$email}:{$ipAddress}" : $email;
        return "auth:failed_attempts:" . md5($identifier);
    }

    /**
     * Obtener clave de caché para bloqueo
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return string Clave de caché
     */
    protected function getLockoutKey(string $email, ?string $ipAddress = null): string
    {
        $identifier = $ipAddress ? "{$email}:{$ipAddress}" : $email;
        return "auth:lockout:" . md5($identifier);
    }

    /**
     * Obtener número de intentos fallidos restantes
     *
     * @param string $email Email del usuario
     * @param string|null $ipAddress Dirección IP
     * @return int Número de intentos restantes antes del bloqueo
     */
    public function getRemainingAttempts(string $email, ?string $ipAddress = null): int
    {
        $key = $this->getAttemptsKey($email, $ipAddress);
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
