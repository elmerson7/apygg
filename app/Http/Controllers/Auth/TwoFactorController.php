<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\AuthServiceInterface;
use App\Contracts\TwoFactorServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Http\Requests\Auth\TwoFactorReauthRequest;
use App\Http\Requests\Auth\TwoFactorVerifyRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2FA TOTP: verificación en el login (público) y gestión del propio 2FA (autenticado).
 */
class TwoFactorController extends Controller
{
    public function __construct(
        protected TwoFactorServiceInterface $twoFactor,
        protected AuthServiceInterface $authService
    ) {}

    /**
     * Completar el login con 2FA (código TOTP o de recuperación)
     */
    public function verify(TwoFactorVerifyRequest $request): JsonResponse
    {
        $result = $this->authService->verifyTwoFactor(
            $request->string('challenge_token')->toString(),
            $request->string('code')->toString(),
            $request->ip()
        );

        return $this->sendSuccess(new AuthResource([
            'user' => $result['user'],
            'access_token' => $result['tokens']['access_token'],
            'refresh_token' => $result['tokens']['refresh_token'],
            'token_type' => 'bearer',
            'expires_in' => $this->authService->getTokenExpiration(),
        ]), 'Login exitoso');
    }

    /**
     * Estado de mi 2FA
     */
    public function show(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->twoFactor->status($this->user($request)), 'Estado del 2FA');
    }

    /**
     * Iniciar la activación: secreto y URI `otpauth://` para el autenticador
     */
    public function setup(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->twoFactor->beginSetup($this->user($request)), 'Escanea el código y confirma con un código del autenticador');
    }

    /**
     * Confirmar la activación con un código; devuelve los códigos de recuperación (única vez)
     */
    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirmSetup($this->user($request), $request->string('code')->toString());

        return $this->sendSuccess(['recovery_codes' => $codes], '2FA activado');
    }

    /**
     * Renovar los códigos de recuperación (contraseña + código)
     */
    public function recoveryCodes(TwoFactorReauthRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes(
            $this->user($request),
            $request->string('password')->toString(),
            $request->string('code')->toString()
        );

        return $this->sendSuccess(['recovery_codes' => $codes], 'Códigos de recuperación renovados');
    }

    /**
     * Desactivar el 2FA (contraseña + código)
     */
    public function destroy(TwoFactorReauthRequest $request): JsonResponse
    {
        $this->twoFactor->disable(
            $this->user($request),
            $request->string('password')->toString(),
            $request->string('code')->toString()
        );

        return $this->sendSuccess(null, '2FA desactivado');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('api');

        return $user;
    }
}
