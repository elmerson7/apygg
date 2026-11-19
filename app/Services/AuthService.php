<?php

namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Exceptions\AccountBlockedException;
use App\Models\Blacklist;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

    protected UserRepositoryInterface $userRepository;

    /**
     * Número máximo de intentos fallidos antes de bloquear
     */
    protected int $maxAttempts = 5;

    /**
     * Tope de fallos contra una misma identidad sumando todas las IP (más alto
     * que el de IP+identidad para no facilitar que alguien bloquee a otro).
     */
    protected int $maxAttemptsPerIdentity = 10;

    /**
     * Tiempo de bloqueo en minutos después de alcanzar maxAttempts
     */
    protected int $lockoutTime = 15;

    public function __construct(
        TokenService $tokenService,
        UserRepositoryInterface $userRepository
    ) {
        $this->tokenService = $tokenService;
        $this->userRepository = $userRepository;
        $this->maxAttempts = (int) config('auth.login.max_attempts', 5);
        $this->maxAttemptsPerIdentity = (int) config('auth.login.max_attempts_per_identity', 10);
        $this->lockoutTime = (int) config('auth.login.lockout_minutes', 15);
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
        $login = $credentials['login'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $login || ! $password) {
            throw new \InvalidArgumentException('Email/username y contraseña son requeridos');
        }

        // Verificar si el usuario está bloqueado por intentos fallidos
        if ($this->isLocked($login, $ipAddress)) {
            LogService::warning('Intento de login bloqueado por intentos fallidos', [
                'identifier' => $login,
                'ip' => $ipAddress,
            ], 'security');

            throw new \Exception('Demasiados intentos fallidos. Intenta nuevamente en '.$this->lockoutTime.' minutos.');
        }

        // Buscar usuario por email o username
        $user = User::where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $user) {
            // Mismo coste que un login real: sin esto la diferencia de tiempo
            // revela qué cuentas existen.
            Hash::make($password);

            $this->recordFailedAttempt($login, $ipAddress);
            LogService::warning('Intento de login fallido - Usuario no encontrado', [
                'identifier' => $login,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Verificar contraseña
        if (! Hash::check($password, $user->password)) {
            $this->recordFailedAttempt($login, $ipAddress);
            LogService::warning('Intento de login fallido - Contraseña incorrecta', [
                'user_id' => $user->id,
                'identifier' => $login,
                'ip' => $ipAddress,
            ], 'security');

            return null;
        }

        // Identidad bloqueada por un administrador (se comprueba tras validar la
        // contraseña para no revelar la lista a quien no es el titular).
        if (Blacklist::isBlocked(
            email: $user->email,
            identityDocument: $user->identity_document,
            userId: $user->id
        )) {
            LogService::warning('Login denegado: usuario en lista negra', [
                'user_id' => $user->id,
                'ip' => $ipAddress,
            ], 'security');

            throw new AccountBlockedException('Cuenta bloqueada');
        }

        // Rehash automático si la contraseña usa un algoritmo diferente o necesita actualización
        // Esto permite migrar de bcrypt a argon2id automáticamente
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($password);
            $this->userRepository->update(['password' => $user->password], $user->id);

            LogService::info('Contraseña rehasheada automáticamente durante login', [
                'user_id' => $user->id,
                'identifier' => $login,
            ], 'security');
        }

        // Limpiar intentos fallidos al autenticar exitosamente
        $this->clearFailedAttempts($login, $ipAddress);

        // Generar tokens
        try {
            $tokens = $this->tokenService->generateTokens($user);

            LogService::info('Autenticación exitosa', [
                'user_id' => $user->id,
                'identifier' => $login,
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
        $userId = $this->tokenService->getUserFromToken($refreshToken)?->id;

        return $userId ? $this->userRepository->find($userId) : null;
    }

    /**
     * Revocar token (logout)
     *
     * @param  string|null  $token  Token a revocar
     * @return bool True si se revocó exitosamente o ya estaba revocado
     *
     * @throws JWTException
     */
    public function revokeToken(?string $token = null, ?string $refreshToken = null): bool
    {
        try {
            // Intentar obtener usuario antes de revocar (para logging y eventos)
            $user = $this->tokenService->getUserFromToken($token);

            // El refresh token de esta sesión se revoca primero: revocar el
            // access deja el estado del servicio de JWT apuntando a otro token.
            if ($refreshToken && $user) {
                $this->tokenService->revokeRefreshToken($refreshToken, $user);
            }

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
     * Bloquea tanto por la pareja identidad+IP como por la identidad sola: rotar
     * de IP no esquiva el límite de la cuenta.
     *
     * @param  string  $identifier  Email o username
     * @param  string|null  $ipAddress  Dirección IP
     * @return bool True si está bloqueado
     */
    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        if (Cache::has($this->getLockoutKey($identifier))) {
            return true;
        }

        return $ipAddress !== null && Cache::has($this->getLockoutKey($identifier, $ipAddress));
    }

    /**
     * Registrar intento fallido
     *
     * Contadores atómicos (RateLimiter::hit usa el incremento atómico de la caché).
     *
     * @param  string  $identifier  Email o username
     * @param  string|null  $ipAddress  Dirección IP
     */
    protected function recordFailedAttempt(string $identifier, ?string $ipAddress = null): void
    {
        $counters = [[null, $this->maxAttemptsPerIdentity]];

        if ($ipAddress !== null) {
            $counters[] = [$ipAddress, $this->maxAttempts];
        }

        foreach ($counters as [$ip, $max]) {
            $key = $this->getAttemptsKey($identifier, $ip);

            RateLimiter::hit($key, $this->lockoutTime * 60);
            $attempts = RateLimiter::attempts($key);

            // Si alcanzó el máximo de intentos, bloquear
            if ($attempts >= $max) {
                Cache::put($this->getLockoutKey($identifier, $ip), true, now()->addMinutes($this->lockoutTime));

                LogService::warning('Usuario bloqueado por intentos fallidos', [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'attempts' => $attempts,
                    'lockout_minutes' => $this->lockoutTime,
                ], 'security');
            }
        }
    }

    /**
     * Limpiar intentos fallidos después de autenticación exitosa
     *
     * @param  string  $identifier  Email o username
     * @param  string|null  $ipAddress  Dirección IP
     */
    protected function clearFailedAttempts(string $identifier, ?string $ipAddress = null): void
    {
        foreach (array_unique([null, $ipAddress]) as $ip) {
            RateLimiter::clear($this->getAttemptsKey($identifier, $ip));
            Cache::forget($this->getLockoutKey($identifier, $ip));
        }
    }

    /**
     * Normalizar la identidad: mayúsculas y espacios no crean contadores nuevos.
     */
    protected function normalizeIdentifier(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    /**
     * Obtener clave de caché para intentos fallidos
     *
     * Sin IP, la clave es la de la identidad sola.
     */
    protected function getAttemptsKey(string $identifier, ?string $ipAddress = null): string
    {
        $key = $this->normalizeIdentifier($identifier).($ipAddress ? "|{$ipAddress}" : '');

        return 'auth:failed_attempts:'.sha1($key);
    }

    /**
     * Obtener clave de caché para bloqueo
     */
    protected function getLockoutKey(string $identifier, ?string $ipAddress = null): string
    {
        $key = $this->normalizeIdentifier($identifier).($ipAddress ? "|{$ipAddress}" : '');

        return 'auth:lockout:'.sha1($key);
    }

    /**
     * Obtener número de intentos fallidos restantes
     *
     * @param  string  $identifier  Email o username
     * @param  string|null  $ipAddress  Dirección IP
     * @return int Número de intentos restantes antes del bloqueo
     */
    public function getRemainingAttempts(string $identifier, ?string $ipAddress = null): int
    {
        $byIdentity = $this->maxAttemptsPerIdentity - RateLimiter::attempts($this->getAttemptsKey($identifier));
        $byIp = $ipAddress !== null
            ? $this->maxAttempts - RateLimiter::attempts($this->getAttemptsKey($identifier, $ipAddress))
            : $byIdentity;

        return max(0, min($byIdentity, $byIp));
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
