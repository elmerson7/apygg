<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Services\LogService;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Services\AuthService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class AuthController
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }
    /**
     * Login de usuario
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only(['email', 'password']);

        try {
            // Autenticar usando AuthService
            $result = $this->authService->authenticate($credentials, $request->ip());

            if (!$result) {
                $remainingAttempts = $this->authService->getRemainingAttempts(
                    $credentials['email'],
                    $request->ip()
                );

                return ApiResponse::unauthorized(
                    'Credenciales inválidas' . ($remainingAttempts > 0 ? ". Intentos restantes: {$remainingAttempts}" : '')
                );
            }

            $user = $result['user'];
            $tokens = $result['tokens'];

            // Retornar respuesta con tokens
            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'bearer',
                    'expires_in' => $this->authService->getTokenExpiration(),
                ]),
                'Login exitoso'
            );
        } catch (\Exception $e) {
            // Manejar bloqueo por intentos fallidos
            if (str_contains($e->getMessage(), 'Demasiados intentos')) {
                return ApiResponse::unauthorized($e->getMessage());
            }

            LogService::error('Error en login', [
                'email' => $credentials['email'] ?? null,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return ApiResponse::error('Error al procesar el login', 500);
        }
    }

    /**
     * Registro de nuevo usuario
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            // Crear usuario
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // Generar tokens para el nuevo usuario
            $tokens = $this->authService->generateTokens($user);

            // Registrar registro exitoso
            LogService::info('Usuario registrado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ], 'security');

            // Retornar respuesta con tokens
            return ApiResponse::created(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'bearer',
                    'expires_in' => $this->authService->getTokenExpiration(),
                ]),
                'Usuario registrado exitosamente'
            );
        } catch (JWTException $e) {
            LogService::error('Error JWT en registro', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al generar tokens de autenticación', 500);
        } catch (\Exception $e) {
            LogService::error('Error en registro', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al procesar el registro', 500);
        }
    }

    /**
     * Logout y revocación de token
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();

            // Revocar token usando AuthService
            $this->authService->revokeToken();

            return ApiResponse::success(null, 'Logout exitoso');
        } catch (JWTException $e) {
            LogService::error('Error JWT en logout', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al procesar el logout', 500);
        } catch (\Exception $e) {
            LogService::error('Error en logout', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al procesar el logout', 500);
        }
    }

    /**
     * Renovar access token usando refresh token
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            // Renovar tokens usando AuthService (con rotación)
            $tokens = $this->authService->refreshToken();

            // Obtener usuario autenticado
            $user = Auth::guard('api')->user();

            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'bearer',
                    'expires_in' => $this->authService->getTokenExpiration(),
                ]),
                'Token renovado exitosamente'
            );
        } catch (JWTException $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::unauthorized('Token inválido o expirado');
        } catch (\Exception $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::unauthorized('Error al renovar el token');
        }
    }

    /**
     * Obtener datos del usuario autenticado
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return ApiResponse::unauthorized('Usuario no autenticado');
            }

            return ApiResponse::success([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'updated_at' => $user->updated_at->toIso8601String(),
            ], 'Usuario obtenido exitosamente');
        } catch (\Exception $e) {
            LogService::error('Error al obtener usuario', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al obtener datos del usuario', 500);
        }
    }
}
