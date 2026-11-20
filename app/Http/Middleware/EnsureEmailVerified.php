<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige correo verificado (alias `verified`, tras `auth:api`). Responde 403 `email_not_verified`.
 *
 *   Route::post('/pedidos', ...)->middleware(['auth:api', 'verified']);
 *
 * `AUTH_EMAIL_VERIFICATION_ENFORCE=false` lo desactiva en bloque (p. ej. en local).
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.email_verification.enforce', true)) {
            return $next($request);
        }

        $user = $request->user('api');

        if ($user === null || $user->email_verified_at === null) {
            return ApiResponse::error(
                'Debes verificar tu correo electrónico para usar esta función',
                403,
                [],
                null,
                ErrorCode::EmailNotVerified
            );
        }

        return $next($request);
    }
}
