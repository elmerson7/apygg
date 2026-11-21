<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\AuthServiceInterface;
use App\Contracts\OtpLoginServiceInterface;
use App\Http\Controllers\Auth\Concerns\RespondsWithSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use Illuminate\Http\JsonResponse;

/**
 * Login sin contraseña por código de un solo uso (módulo `otp-login`, docs/otp-login.md).
 */
class OtpLoginController extends Controller
{
    use RespondsWithSession;

    public function __construct(
        protected AuthServiceInterface $authService,
        private readonly OtpLoginServiceInterface $otp,
    ) {}

    /**
     * Pedir un código de acceso
     *
     * Responde siempre lo mismo, tenga o no cuenta el destino.
     */
    public function request(OtpRequestRequest $request): JsonResponse
    {
        [$channel, $destination] = $this->target($request->input('email'), $request->input('phone'));

        $expiresIn = $this->otp->request($channel, $destination, $request->ip());

        return $this->sendSuccess(['expires_in' => $expiresIn], 'Si el destino es válido, te hemos enviado un código', 202);
    }

    /**
     * Canjear el código por la sesión
     *
     * Con 2FA activo responde el desafío (`two_factor_required`) igual que `POST /auth/login`.
     */
    public function verify(OtpVerifyRequest $request): JsonResponse
    {
        [$channel, $destination] = $this->target($request->input('email'), $request->input('phone'));

        $result = $this->otp->verify($channel, $destination, $request->string('code')->toString(), $request->ip());

        return $this->sessionResponse($result);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function target(mixed $email, mixed $phone): array
    {
        return is_string($email) && $email !== ''
            ? [OtpLoginServiceInterface::CHANNEL_MAIL, $email]
            : [OtpLoginServiceInterface::CHANNEL_SMS, (string) $phone];
    }
}
