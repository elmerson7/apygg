<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\EmailVerificationServiceInterface;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificación de correo: confirmar con el token del enlace y reenviar el correo.
 */
class EmailVerificationController extends Controller
{
    public function __construct(protected EmailVerificationServiceInterface $verification) {}

    /**
     * Verificar el correo con el token del enlace (público: el enlace se abre sin sesión)
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = $this->verification->verify($request->string('token')->toString());

        return $this->sendSuccess(
            ['email_verified_at' => $user->email_verified_at?->toIso8601String()],
            'Correo verificado'
        );
    }

    /**
     * Reenviar el correo de verificación (límite por usuario y hora)
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        if ($user->email_verified_at !== null) {
            return $this->sendSuccess(['already_verified' => true], 'El correo ya está verificado');
        }

        if (! $this->verification->sendThrottled($user)) {
            throw new ApiException(
                'Demasiados reenvíos. Intenta más tarde.',
                429,
                [],
                'rate_limited',
                null,
                ['retry_after' => $this->verification->retryAfter($user)]
            );
        }

        return $this->sendSuccess(['already_verified' => false], 'Correo de verificación enviado');
    }
}
