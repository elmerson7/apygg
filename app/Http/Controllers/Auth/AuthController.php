<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\AuthServiceInterface;
use App\Http\Controllers\Auth\Concerns\RespondsWithSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\LogService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Autenticación: login, registro, logout, renovación de token y usuario en sesión.
 *
 * No captura excepciones: las reglas de negocio (`LoginLockedException`,
 * `AccountBlockedException`) y los fallos de JWT llegan al handler global, que
 * responde en RFC 7807 y registra el error.
 */
class AuthController extends Controller
{
    use RespondsWithSession;

    public function __construct(protected AuthServiceInterface $authService) {}

    /**
     * Login de usuario
     */
    #[BodyParameter('login', 'Email o username del usuario', required: true, example: 'admin@example.com')]
    #[BodyParameter('password', 'Contraseña del usuario (mínimo 8 caracteres)', required: true, example: 'password')]
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->input('login');

        $result = $this->authService->authenticate(
            ['login' => $login, 'password' => $request->input('password')],
            $request->ip()
        );

        if (! $result) {
            $remainingAttempts = $this->authService->getRemainingAttempts($login, $request->ip());

            return $this->sendError(
                'Credenciales inválidas'.($remainingAttempts > 0 ? ". Intentos restantes: {$remainingAttempts}" : ''),
                401
            );
        }

        return $this->sessionResponse($result);
    }

    /**
     * Registro de nuevo usuario
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string} $data */
        $data = $request->validated();
        $result = $this->authService->register($data);
        $user = $result['user'];

        LogService::info('Usuario registrado', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ], 'security');

        return $this->sendSuccess(
            $this->authPayload($user, $result['tokens']),
            'Usuario registrado exitosamente',
            201
        );
    }

    /**
     * Logout y revocación de token
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoca el access token de la petición y, si el cliente lo envía,
        // el refresh token de esa misma sesión.
        $refreshToken = $request->input('refresh_token');

        $this->authService->revokeToken(
            null,
            is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null
        );

        return $this->sendSuccess(null, 'Logout exitoso');
    }

    /**
     * Renovar access token usando refresh token
     * Ruta pública (sin auth:api) porque el access token ya expiró.
     * La validación del refresh token se hace internamente en TokenService.
     */
    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->input('refresh_token');

        if (! $refreshToken) {
            return $this->sendError('Refresh token no proporcionado', 401);
        }

        // Renovar tokens (con rotación). Un token inválido o expirado lanza
        // JWTException y el handler responde 401.
        $result = $this->authService->refreshToken($refreshToken);
        /** @var array{access_token: string, refresh_token: string} $tokens */
        $tokens = $result;

        return $this->sendSuccess(
            $this->authPayload($result['user'], $tokens),
            'Token renovado exitosamente'
        );
    }

    /**
     * Obtener datos del usuario autenticado
     */
    public function me(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (! $user) {
            return $this->sendError('Usuario no autenticado', 401);
        }

        // Recargar con relaciones
        $user = User::with(['roles', 'profile'])->find($user->id);

        if (! $user instanceof User) {
            return $this->sendError('Usuario no autenticado', 401);
        }

        // Permisos efectivos: directos + los de sus roles (API de Spatie)
        $allPermissions = $user->getAllPermissions()->pluck('name')->unique()->values();

        return $this->sendSuccess([
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->profile?->first_name,
            'last_name' => $user->profile?->last_name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
            /** @var list<string> */
            'roles' => $user->roles->pluck('name')->toArray(),
            /** @var list<string> */
            'permissions' => $allPermissions->toArray(),
            /** @var array<string, mixed>|null */
            'preferences' => $user->preferences,
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ], 'Usuario obtenido exitosamente');
    }
}
