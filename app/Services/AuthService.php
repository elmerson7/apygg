<?php

namespace App\Services;

use App\Contracts\AuthServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Exceptions\AccountBlockedException;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\LoginLockedException;
use App\Models\Blacklist;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
class AuthService implements AuthServiceInterface
{
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
        protected TokenServiceInterface $tokenService,
        protected UserServiceInterface $userService
    ) {
        $this->maxAttempts = (int) config('auth.login.max_attempts', 5);
        $this->maxAttemptsPerIdentity = (int) config('auth.login.max_attempts_per_identity', 10);
        $this->lockoutTime = (int) config('auth.login.lockout_minutes', 15);
    }

    /**
     * Autenticar usuario con credenciales
     *
     * @param  array<string, mixed>  $credentials  Credenciales ['identity_document' => string, 'password' => string]
     * @param  string|null  $ipAddress  Dirección IP del cliente
     * @return array<string, mixed> ['user' => User, 'tokens' => array] o null si falla
     *
     * @throws LoginLockedException Si la identidad está bloqueada por intentos fallidos
     * @throws AccountBlockedException Si la cuenta está en la lista negra
     */
    public function authenticate(array $credentials, ?string $ipAddress = null): ?array
    {
        $login = $credentials['login'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $login || ! $password) {
            throw BusinessLogicException::ruleViolation('Email/username y contraseña son requeridos', 'CREDENTIALS_REQUIRED');
        }

        // Verificar si el usuario está bloqueado por intentos fallidos
        if ($this->isLocked($login, $ipAddress)) {
            LogService::warning('Intento de login bloqueado por intentos fallidos', [
                'identifier' => $login,
                'ip' => $ipAddress,
            ], 'security');

            throw new LoginLockedException($this->lockoutTime);
        }

        // Buscar usuario por email o username
        // El email no distingue mayúsculas; el username sí.
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($login)])
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

            throw new AccountBlockedException;
        }

        // Rehash automático si la contraseña usa un algoritmo diferente o necesita actualización
        // Esto permite migrar de bcrypt a argon2id automáticamente
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($password);
            $user->save();

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

            // Evento estándar de Laravel: lo escucha LogAuthenticationEvents
            // (único registro de seguridad del login).
            event(new Login('api', $user, false));

            // Evento de dominio: solo alimenta los webhooks (user.logged_in).
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

            throw new ApiException('Error al generar tokens de autenticación', 500, [], 'token_generation_error', null, [], $e);
        }
    }

    /**
     * Registrar un usuario nuevo y abrirle sesión.
     *
     * Delega la creación en UserService (transacción usuario + perfil + rol por
     * defecto, evento `UserCreated` con bienvenida, log y webhook) y genera los tokens.
     *
     * @param  array{name: string, email: string, password: string}  $data  Datos ya validados
     * @return array<string, mixed> User, tokens: array}
     *
     * @throws JWTException
     */
    public function register(array $data): array
    {
        $user = $this->userService->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return [
            'user' => $user,
            'tokens' => $this->tokenService->generateTokens($user),
        ];
    }

    /**
     * Generar tokens para un usuario
     *
     * @param  User  $user  Usuario para el cual generar tokens
     * @param  array<string, mixed>  $customClaims  Claims personalizados adicionales
     * @return array<string, mixed> ['access_token' => string, 'refresh_token' => string]
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
     * @return array<string, mixed> ['access_token' => string, 'refresh_token' => string, 'user' => User]
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

        return $userId ? User::find($userId) : null;
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

                    // Evento estándar de Laravel (registro de seguridad) y de dominio (webhooks)
                    event(new Logout('api', $user));
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
