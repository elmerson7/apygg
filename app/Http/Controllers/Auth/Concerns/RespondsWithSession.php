<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Contracts\AuthServiceInterface;
use App\Http\Resources\Auth\AuthResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Respuesta común de todo login (contraseña, social, código): con 2FA activo el desafío, si no usuario + tokens.
 * El controller que la usa tiene `$authService`.
 *
 * @property AuthServiceInterface $authService
 */
trait RespondsWithSession
{
    /**
     * @param  array<string, mixed>  $result  salida de `AuthServiceInterface::authenticate|authenticateVerified`
     */
    protected function sessionResponse(array $result, string $message = 'Login exitoso'): JsonResponse
    {
        // Con 2FA activo no hay tokens todavía: el cliente completa en POST /auth/2fa/verify.
        if (isset($result['two_factor'])) {
            return $this->sendSuccess([
                'two_factor_required' => true,
                'challenge_token' => $result['two_factor']['challenge_token'],
                'expires_in' => $result['two_factor']['expires_in'],
            ], 'Verificación en dos pasos requerida');
        }

        return $this->sendSuccess($this->authPayload($result['user'], $result['tokens']), $message);
    }

    /**
     * Payload común de las respuestas con sesión (usuario + tokens).
     *
     * @param  array{access_token: string, refresh_token: string}  $tokens
     */
    protected function authPayload(User $user, array $tokens): AuthResource
    {
        return new AuthResource([
            'user' => $user,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'bearer',
            'expires_in' => $this->authService->getTokenExpiration(),
        ]);
    }
}
