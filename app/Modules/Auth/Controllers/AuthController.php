<?php

namespace App\Modules\Auth\Controllers;

use App\Helpers\ApiResponse;
use App\Infrastructure\Services\LogService;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Resources\AuthResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class AuthController
{
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
            // Intentar autenticación con JWT
            if (!$token = JWTAuth::attempt($credentials)) {
                LogService::warning('Intento de login fallido', [
                    'email' => $credentials['email'],
                    'ip' => $request->ip(),
                ], 'security');

                return ApiResponse::unauthorized('Credenciales inválidas');
            }

            // Obtener usuario autenticado
            $user = JWTAuth::user();

            // Registrar login exitoso
            LogService::info('Login exitoso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ], 'security');

            // Retornar respuesta con token
            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // En segundos
                ]),
                'Login exitoso'
            );
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException $e) {
            LogService::error('Error JWT en login', [
                'email' => $credentials['email'],
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return ApiResponse::unauthorized('Error al generar token de autenticación');
        } catch (\Exception $e) {
            LogService::error('Error en login', [
                'email' => $credentials['email'],
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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

            // Generar token para el nuevo usuario
            $token = JWTAuth::fromUser($user);

            // Registrar registro exitoso
            LogService::info('Usuario registrado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ], 'security');

            // Retornar respuesta con token
            return ApiResponse::created(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // En segundos
                ]),
                'Usuario registrado exitosamente'
            );
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

            // Invalidar token (agregar a blacklist)
            JWTAuth::invalidate(JWTAuth::getToken());

            // Registrar logout
            if ($user) {
                LogService::info('Logout exitoso', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ], 'security');
            }

            return ApiResponse::success(null, 'Logout exitoso');
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
            $user = Auth::guard('api')->user();

            // Generar nuevo token
            $token = JWTAuth::refresh(JWTAuth::getToken());

            // Registrar refresh
            if ($user) {
                LogService::info('Token renovado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ], 'security');
            }

            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // En segundos
                ]),
                'Token renovado exitosamente'
            );
        } catch (\Exception $e) {
            LogService::error('Error al renovar token', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::unauthorized('Token inválido o expirado');
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
