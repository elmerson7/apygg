<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        if (!$token = auth('api')->attempt($data)) {
            return response()->json([
                'type'   => 'about:blank',
                'title'  => 'Credenciales inválidas',
                'status' => Response::HTTP_UNAUTHORIZED,
            ], Response::HTTP_UNAUTHORIZED);
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

        return response()->json([
            'token_type'           => 'Bearer',
            'access_token'         => $accessToken,
            'expires_in'           => $accessTtl * 60,        // en segundos
            'refresh_token'        => $refreshToken,
            'refresh_expires_in'   => $refreshTtl * 60,       // en segundos
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * POST /auth/refresh
     * Header Authorization: Bearer <refresh_token>  (o body.refresh_token)
     * Rotación: invalida el refresh usado y entrega uno nuevo + nuevo access
     */
    public function refresh(Request $request)
    {
        $rawToken = $request->bearerToken() ?? $request->string('refresh_token')->toString();

        if (!$rawToken) {
            throw ValidationException::withMessages([
                'refresh_token' => ['Falta el refresh_token.'],
            ]);
        }

        try {
            // Debe ser un refresh (claim typ=refresh)
            $payload = JWTAuth::setToken($rawToken)->getPayload();
            if (($payload->get('typ') ?? null) !== 'refresh') {
                return response()->json(['message' => 'Debes enviar un refresh_token.'], Response::HTTP_UNAUTHORIZED);
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

            return response()->json([
                'token_type'           => 'Bearer',
                'access_token'         => $newAccess,
                'expires_in'           => $accessTtl * 60,
                'refresh_token'        => $newRefresh,
                'refresh_expires_in'   => $refreshTtl * 60,
            ], Response::HTTP_OK);

        } catch (JWTException $e) {
            return response()->json(['message' => 'Refresh inválido o expirado.'], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * POST /auth/logout
     * Header Authorization: Bearer <access_token>
     * Body opcional: { refresh_token } para invalidarlo también
     */
    public function logout(Request $request)
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
        if ($refresh = $request->string('refresh_token')->toString()) {
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

        return response()->json(['message' => 'Logout OK'], Response::HTTP_OK);
    }
}
