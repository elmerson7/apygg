<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use App\Http\Resources\Auth\RefreshTokenResource;
use App\Http\Resources\UserResource;
use App\Services\Logging\AuthLogger;
use App\Services\Logging\SecurityLogger;
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
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        if (!$token = auth('api')->attempt($data)) {
            // Log failed login attempt
            AuthLogger::logLoginFailed($request, 'Invalid credentials');
            
            // Check for potential brute force
            if (AuthLogger::isSuspiciousIp($request->ip())) {
                SecurityLogger::logBruteForceAttempt(context: [
                    'email' => $data['email'] ?? null,
                    'failed_attempts' => AuthLogger::getFailedAttemptsFromIp($request->ip())
                ], request: $request);
            }
            
            return response()->json([
                'success' => false,
                'type'   => 'https://damblix.dev/errors/InvalidCredentials',
                'title'  => 'Credenciales inválidas',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'El email o la contraseña son incorrectos.',
                'instance' => $request->fullUrl(),
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
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

        // Log successful login
        $accessPayload = JWTAuth::setToken($accessToken)->getPayload();
        AuthLogger::logLogin($user->id, $request, $accessPayload->get('jti'));

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
                // Log failed refresh attempt
                AuthLogger::logRefreshFailed($request, 'Invalid token type');
                
                return response()->json([
                    'success' => false,
                    'type' => 'https://damblix.dev/errors/InvalidTokenType',
                    'title' => 'Tipo de token inválido',
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'detail' => 'Debes enviar un refresh_token.',
                    'instance' => $request->fullUrl(),
                    'meta' => [
                        'trace_id' => $request->attributes->get('trace_id'),
                        'timestamp' => now()->toISOString(),
                        'version' => '1.0',
                    ],
                ], Response::HTTP_UNAUTHORIZED, [
                    'Content-Type' => 'application/problem+json'
                ]);
            }

            $user = JWTAuth::setToken($rawToken)->authenticate();
            $oldJti = $payload->get('jti');

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

            // Log successful refresh
            $newRefreshPayload = JWTAuth::setToken($newRefresh)->getPayload();
            AuthLogger::logRefresh($user->id, $request, $oldJti, $newRefreshPayload->get('jti'));

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
            // Log failed refresh attempt
            AuthLogger::logRefreshFailed($request, 'Invalid or expired token');
            
            return response()->json([
                'success' => false,
                'type' => 'https://damblix.dev/errors/InvalidToken',
                'title' => 'Token inválido',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Refresh inválido o expirado.',
                'instance' => $request->fullUrl(),
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
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
        $user = auth('api')->user();
        $accessJti = null;
        
        // Invalida access actual (si llega)
        if ($request->bearerToken()) {
            try {
                $accessPayload = JWTAuth::setToken($request->bearerToken())->getPayload();
                $accessJti = $accessPayload->get('jti');
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

        // Log logout event
        if ($user) {
            AuthLogger::logLogout($user->id, $request, $accessJti);
        }

        return response()->apiJson([
            'message' => 'Logout exitoso',
        ], Response::HTTP_OK);
    }

    /**
     * GET /auth/me
     * Devuelve los datos del usuario autenticado
     */
    public function me(Request $request)
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'type'   => 'https://damblix.dev/errors/Unauthenticated',
                'title'  => 'Usuario no autenticado',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Debes estar autenticado para acceder a este recurso.',
                'instance' => $request->fullUrl(),
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ], Response::HTTP_UNAUTHORIZED, [
                'Content-Type' => 'application/problem+json'
            ]);
        }

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
