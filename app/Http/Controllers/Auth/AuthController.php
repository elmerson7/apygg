<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use App\Http\Resources\Auth\RefreshTokenResource;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * POST /auth/login
     * Body: { email, password }
     * Devuelve access_token (ttl corto) + refresh_token (ttl largo)
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        if (!$token = auth('api')->attempt($data)) {
            return response()->json([
                'type'   => 'https://damblix.dev/errors/InvalidCredentials',
                'title'  => 'Credenciales inválidas',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'El email o la contraseña son incorrectos.',
                'instance' => $request->fullUrl(),
            ], Response::HTTP_UNAUTHORIZED, [
                'Content-Type' => 'application/problem+json'
            ]);
        }

        $user = auth('api')->user();

        $accessTtl  = (int) config('jwt.ttl');          // minutos
        $refreshTtl = (int) config('jwt.refresh_ttl');  // minutos

        // Crear access token con TTL personalizado usando factory
        JWTAuth::factory()->setTTL($accessTtl);
        $accessToken = JWTAuth::claims(['typ' => 'access'])->fromUser($user);

        // Crear refresh token con TTL personalizado usando factory
        JWTAuth::factory()->setTTL($refreshTtl);
        $refreshToken = JWTAuth::claims(['typ' => 'refresh'])->fromUser($user);

        // Restaurar TTL por defecto
        JWTAuth::factory()->setTTL((int) config('jwt.ttl'));

        $tokenData = [
            'access_token' => $accessToken,
            'expires_in' => $accessTtl * 60,        // en segundos
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => $refreshTtl * 60,       // en segundos
            'user' => $user,
        ];

        return AuthTokenResource::make($tokenData)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * POST /auth/refresh
     * Header Authorization: Bearer <refresh_token>  (o body.refresh_token)
     * Rotación: invalida el refresh usado y entrega uno nuevo + nuevo access
     */
    public function refresh(RefreshRequest $request)
    {
        $rawToken = $request->getRefreshToken();

        if (!$rawToken) {
            throw ValidationException::withMessages([
                'refresh_token' => ['Falta el refresh_token.'],
            ]);
        }

        try {
            // Debe ser un refresh (claim typ=refresh)
            $payload = JWTAuth::setToken($rawToken)->getPayload();
            if (($payload->get('typ') ?? null) !== 'refresh') {
                return response()->json([
                    'type' => 'https://damblix.dev/errors/InvalidTokenType',
                    'title' => 'Tipo de token inválido',
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'detail' => 'Debes enviar un refresh_token.',
                    'instance' => $request->fullUrl(),
                ], Response::HTTP_UNAUTHORIZED, [
                    'Content-Type' => 'application/problem+json'
                ]);
            }

            $user = JWTAuth::setToken($rawToken)->authenticate();

            // Invalida (blacklist) el refresh usado — ROTACIÓN
            JWTAuth::invalidate($rawToken);

            $accessTtl  = (int) config('jwt.ttl');
            $refreshTtl = (int) config('jwt.refresh_ttl');

            // Crear nuevo access token con TTL personalizado usando factory
            JWTAuth::factory()->setTTL($accessTtl);
            $newAccess = JWTAuth::claims(['typ' => 'access'])->fromUser($user);

            // Crear nuevo refresh token con TTL personalizado usando factory
            JWTAuth::factory()->setTTL($refreshTtl);
            $newRefresh = JWTAuth::claims(['typ' => 'refresh'])->fromUser($user);

            // Restaurar TTL por defecto
            JWTAuth::factory()->setTTL((int) config('jwt.ttl'));

            $tokenData = [
                'access_token' => $newAccess,
                'expires_in' => $accessTtl * 60,
                'refresh_token' => $newRefresh,
                'refresh_expires_in' => $refreshTtl * 60,
            ];

            return RefreshTokenResource::make($tokenData)
                ->response()
                ->setStatusCode(Response::HTTP_OK);

        } catch (JWTException $e) {
            return response()->json([
                'type' => 'https://damblix.dev/errors/InvalidToken',
                'title' => 'Token inválido',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Refresh inválido o expirado.',
                'instance' => $request->fullUrl(),
            ], Response::HTTP_UNAUTHORIZED, [
                'Content-Type' => 'application/problem+json'
            ]);
        }
    }

    /**
     * POST /auth/logout
     * Header Authorization: Bearer <access_token>
     * Body opcional: { refresh_token } para invalidarlo también
     */
    public function logout(LogoutRequest $request)
    {
        // Invalida access actual (si llega)
        if ($request->bearerToken()) {
            try {
                JWTAuth::invalidate($request->bearerToken());
            } catch (\Throwable $e) {
                // ya inválido/expirado; continuar
            }
        }

        // Invalida refresh si lo envían
        if ($refresh = $request->getRefreshToken()) {
            try {
                JWTAuth::invalidate($refresh);
            } catch (\Throwable $e) {
                // ignorar errores de token ya inválido
            }
        }

        // Cierra sesión del guard (higiene)
        try {
            auth('api')->logout();
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Logout exitoso',
            'timestamp' => now()->toISOString(),
        ], Response::HTTP_OK);
    }
}
